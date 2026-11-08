import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Badge,
    Button,
    Card,
    FeatureTile,
    FormErrors,
    PageHeader,
    Select,
    SettingsSectionHead,
    ui,
    t,
    cx,
} from '../../ui';
import { AdvancedClubTabs } from './clubTabs';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.performance', 'Performance') },
];

const clearedItems = [
    t('admin.performance.clear_app', 'Application cache, including settings, counts, and search'),
    t('admin.performance.clear_compiled', 'Compiled pages, routes, and config cache'),
    t('admin.performance.clear_catalog', 'Storefront home and category cache'),
];

const keptItems = [
    t('admin.performance.keep_orders', 'Orders, customers, and catalog data'),
    t('admin.performance.keep_assets', 'Built CSS, JavaScript, and uploaded images'),
];

function StatusRow({ label, value, tone = 'neutral' }) {
    return (
        <div className="flex items-center justify-between gap-3 py-2">
            <span className="text-sm text-neutral-700">{label}</span>
            <Badge tone={tone} dot={false}>{value}</Badge>
        </div>
    );
}

function FileList({ rows }) {
    if (!rows?.length) {
        return null;
    }

    return (
        <ul className="divide-y divide-neutral-100 rounded-xl border border-neutral-200">
            {rows.map((row) => (
                <li key={row.file} className="flex items-baseline justify-between gap-3 px-3 py-2">
                    <span className="min-w-0">
                        <span className="block text-sm font-medium text-neutral-800">{row.name}</span>
                        <span className={cx(ui.muted, 'block break-all')}>{row.file}</span>
                    </span>
                    <span className="shrink-0 text-sm tabular-nums text-neutral-600">{row.size}</span>
                </li>
            ))}
        </ul>
    );
}

export default function Performance({
    debug,
    is_production: isProduction = false,
    cache_driver: cacheDriver,
    catalog_ttl: catalogTtl,
    catalog_version: catalogVersion,
    images_missing: imagesMissing = 0,
    search,
    profiler,
    webp,
    opcache,
    octane = null,
    stack = null,
    object_storage: objectStorage = null,
    assets,
    slow = [],
    benchmark = null,
    api_cache: apiCache = true,
}) {
    const form = useForm({
        catalog_ttl: catalogTtl || 'auto',
    });
    const [clearing, setClearing] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [benchmarking, setBenchmarking] = useState(false);
    const [debugOn, setDebugOn] = useState(!!debug);
    const [profilerOn, setProfilerOn] = useState(!!profiler);

    const saveCache = (event) => {
        event.preventDefault();
        form.put('/admin/performance', { preserveScroll: true });
    };

    const clearCache = () => {
        setClearing(true);
        router.post('/admin/performance/clear', {}, {
            preserveScroll: true,
            onFinish: () => setClearing(false),
        });
    };

    const setDebug = (on) => {
        setDebugOn(on);
        router.put('/admin/performance/debug', { enabled: on }, { preserveScroll: true });
    };

    const refreshStorefront = () => {
        setRefreshing(true);
        router.post('/admin/performance/refresh-storefront', {}, {
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        });
    };

    const setProfiler = (on) => {
        setProfilerOn(on);
        router.put('/admin/advanced/profiler', { enabled: on }, { preserveScroll: true });
    };

    const runBenchmark = () => {
        setBenchmarking(true);
        router.post('/admin/performance/benchmark', { runs: 3 }, {
            preserveScroll: true,
            onFinish: () => setBenchmarking(false),
        });
    };

    const benchmarkPaths = benchmark?.paths && typeof benchmark.paths === 'object'
        ? Object.entries(benchmark.paths)
        : [];

    return (
        <Layout>
            <PageHeader
                title={t('admin.performance.title', 'Performance')}
                crumbs={crumbs}
                description={t('admin.performance.lead', 'Settings that change how fast the shop responds. The storefront CSS and JavaScript are already combined by the React build.')}
            />
            <AdvancedClubTabs current="performance" />

            <div className={cx(ui.formLayout, 'mb-6')}>
                <div className={ui.formMain}>
                    <Card className="space-y-3">
                        <SettingsSectionHead
                            icon="alert-triangle"
                            title={t('admin.performance.debug_title', 'Debug mode')}
                            hint={t('admin.performance.debug_hint', 'Shows the real error on the page when something breaks. Leave it off on a public shop.')}
                        />
                        <FeatureTile
                            icon="alert-triangle"
                            tone="bg-amber-100 text-amber-700"
                            title={t('admin.performance.debug_toggle', 'Debug mode')}
                            hint={isProduction
                                ? t('admin.performance.debug_locked_hint', 'Locked off: this shop runs in production. Use a staging copy to see full error pages.')
                                : t('admin.performance.debug_toggle_hint', 'On shows full errors to shoppers and staff, and makes each page slower. Off shows a plain error page.')}
                            value={debugOn}
                            onChange={setDebug}
                            disabled={isProduction}
                        />
                        {debugOn ? (
                            <Alert type="warning">
                                {t('admin.performance.debug_on', 'Debug mode is on. Shoppers can see error details. Turn it off before the shop is public.')}
                            </Alert>
                        ) : (
                            <Alert type="success">
                                {t('admin.performance.debug_off', 'Debug mode is off. Shoppers do not see error details.')}
                            </Alert>
                        )}
                    </Card>

                    <Card as="form" className="space-y-4" onSubmit={saveCache}>
                        <SettingsSectionHead
                            icon="database"
                            title={t('admin.performance.catalog_title', 'Storefront cache')}
                            hint={t('admin.performance.catalog_hint', 'Guest home and category pages can be reused instead of reading the database on every visit.')}
                        />
                        <FormErrors errors={form.errors} />
                        <Select value={form.data.catalog_ttl} onChange={(event) => form.setData('catalog_ttl', event.target.value)}>
                            <option value="auto">{t('admin.performance.ttl_auto', 'Automatic — home about 5 minutes, category lists about 2 minutes')}</option>
                            <option value="0">{t('admin.performance.ttl_off', 'Off — read the database on every visit')}</option>
                            <option value="600">{t('admin.performance.ttl_10', '10 minutes')}</option>
                            <option value="3600">{t('admin.performance.ttl_60', '1 hour')}</option>
                        </Select>
                        <p className={ui.muted}>
                            {t('admin.performance.catalog_note', 'Saving a product or category refreshes these pages at once. A longer time does not leave old prices or sold-out products on the shop. Signed-in shoppers still see their own prices immediately.')}
                        </p>
                        <p className={ui.muted}>
                            {t('admin.performance.catalog_version', 'Listing cache version')}: {catalogVersion}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" icon="check" busy={form.processing}>
                                {t('admin.common.save', 'Save')}
                            </Button>
                            <Button type="button" variant="secondary" icon="refresh" busy={refreshing} onClick={refreshStorefront}>
                                {t('admin.performance.refresh_storefront', 'Refresh storefront pages')}
                            </Button>
                        </div>
                    </Card>

                    <Card className="space-y-4">
                        <SettingsSectionHead
                            icon="trash"
                            title={t('admin.performance.cache_title', 'Clear cache')}
                            hint={t('admin.performance.clear_hint', 'Use this when a setting or a compiled page looks stuck. The first visits after a clear are slower while the cache fills again.')}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <h3 className="mb-2 text-sm font-semibold text-neutral-800">{t('admin.performance.cleared', 'Cleared')}</h3>
                                <ul className="space-y-1.5">
                                    {clearedItems.map((item) => (
                                        <li key={item} className="text-sm text-neutral-700">{item}</li>
                                    ))}
                                </ul>
                            </div>
                            <div>
                                <h3 className="mb-2 text-sm font-semibold text-neutral-800">{t('admin.performance.kept', 'Left as they are')}</h3>
                                <ul className="space-y-1.5">
                                    {keptItems.map((item) => (
                                        <li key={item} className="text-sm text-neutral-700">{item}</li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                        <Button type="button" icon="sparkles" busy={clearing} onClick={clearCache}>
                            {t('admin.performance.clear_now', 'Clear cache')}
                        </Button>
                    </Card>

                    <Card className="space-y-4">
                        <SettingsSectionHead
                            icon="layers"
                            title={t('admin.performance.assets_title', 'CSS and JavaScript')}
                            hint={t('admin.performance.assets_hint', 'The React storefront is already one build. Hashed files can stay in the browser. There is no separate combine switch, because combining them again would slow the shop down.')}
                        />
                        {assets?.ready ? (
                            <FileList rows={assets.built} />
                        ) : (
                            <Alert type="warning">
                                {t('admin.performance.assets_missing', 'The storefront build is missing. Run npm run build so shoppers get the combined CSS and JavaScript.')}
                            </Alert>
                        )}
                        {assets?.theme?.length ? (
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold text-neutral-800">{t('admin.performance.theme_css', 'Theme stylesheet')}</h3>
                                <p className={ui.muted}>
                                    {t('admin.performance.theme_css_hint', 'Loaded in addition to the React build. One extra file does not need to be combined.')}
                                </p>
                                <FileList rows={assets.theme} />
                            </div>
                        ) : null}
                        <p className={ui.muted}>
                            {t('admin.performance.browser_cache', 'Apache keeps hashed CSS and JavaScript for a year, other images and styles for a day, and compresses them when the server allows it.')}
                        </p>
                    </Card>

                    <Card className="space-y-4">
                        <SettingsSectionHead
                            icon="gauge"
                            title={t('admin.performance.benchmark_title', 'Measured timings')}
                            hint={t('admin.performance.benchmark_hint', 'Real numbers from this shop and this machine. Not a marketing 10M claim.')}
                        />
                        {benchmark?.measured_at ? (
                            <p className={ui.muted}>
                                {t('admin.performance.benchmark_at', 'Last run')}: {benchmark.measured_at}
                                {' · '}
                                {benchmark.product_count ?? 0} {t('admin.performance.benchmark_products', 'products')}
                                {' · '}
                                PHP {benchmark.php}
                                {' · '}
                                {benchmark.cache_driver}
                            </p>
                        ) : (
                            <p className={ui.muted}>
                                {t('admin.performance.benchmark_empty', 'No benchmark yet. Run one after the shop is seeded.')}
                            </p>
                        )}
                        {benchmarkPaths.length ? (
                            <ul className="divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                                {benchmarkPaths.map(([name, row]) => (
                                    <li key={name} className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                        <span className="min-w-0">
                                            <span className="block text-sm font-medium text-neutral-800">{name}</span>
                                            <span className={cx(ui.muted, 'block break-all')}>{row?.path}</span>
                                        </span>
                                        <span className="shrink-0 text-sm tabular-nums text-neutral-700">
                                            avg {row?.avg_ms ?? '—'} ms · p95 {row?.p95_ms ?? '—'} ms
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        <Button type="button" variant="secondary" icon="gauge" busy={benchmarking} onClick={runBenchmark}>
                            {t('admin.performance.benchmark_run', 'Run benchmark')}
                        </Button>
                    </Card>

                    <Card className="space-y-4">
                        <SettingsSectionHead
                            icon="gauge"
                            title={t('admin.advanced.profiler', 'Profiler')}
                            hint={t('admin.performance.profiler_hint', 'Records time, memory, and SQL for recent requests. Leave it off on a live shop. It slows every page.')}
                        />
                        <FeatureTile
                            icon="gauge"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.performance.profiler_toggle', 'Record slow requests')}
                            hint={t('admin.performance.profiler_toggle_hint', 'Turn it on, browse the slow page, then come back. The full SQL list stays on Advanced → Profiler.')}
                            value={profilerOn}
                            onChange={setProfiler}
                        />
                        {slow.length ? (
                            <ul className="divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                                {slow.map((row) => (
                                    <li key={`${row.url}-${row.time_ms}`} className="px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge tone="info" dot={false}>{row.method}</Badge>
                                            <span className="text-sm font-medium break-all">{row.url}</span>
                                        </div>
                                        <p className={ui.muted}>
                                            {row.time_ms} ms · {row.query_count} SQL · {row.memory_kb} KB
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className={ui.muted}>
                                {t('admin.performance.no_slow', 'No recorded requests yet.')}
                            </p>
                        )}
                        <Button href="/admin/advanced?tab=profiler" variant="secondary">
                            {t('admin.performance.open_profiler', 'Open full profiler')}
                        </Button>
                    </Card>
                </div>

                <aside className={ui.formSide}>
                    <Card className="space-y-1">
                        <SettingsSectionHead
                            icon="gauge"
                            title={t('admin.performance.status_title', 'This server')}
                            hint={t('admin.performance.status_hint', 'Read from this shop. These are not switches.')}
                        />
                        <StatusRow
                            label={t('admin.performance.debug_title', 'Debug mode')}
                            value={debugOn ? t('admin.performance.on', 'On') : t('admin.performance.off', 'Off')}
                            tone={debugOn ? 'warning' : 'success'}
                        />
                        <StatusRow
                            label={t('admin.performance.cache_driver', 'Cache driver')}
                            value={cacheDriver || 'file'}
                        />
                        <StatusRow
                            label={t('admin.performance.api_cache', 'Guest API cache')}
                            value={apiCache ? t('admin.performance.on', 'On') : t('admin.performance.off', 'Off')}
                            tone={apiCache ? 'success' : 'warning'}
                        />
                        <StatusRow
                            label={t('admin.performance.opcache', 'OPcache')}
                            value={opcache?.enabled
                                ? (opcache.hit_rate != null ? `${opcache.hit_rate}%` : t('admin.performance.on', 'On'))
                                : t('admin.performance.unavailable', 'Unavailable')}
                            tone={opcache?.enabled ? 'success' : 'warning'}
                        />
                        <StatusRow
                            label={t('admin.performance.octane', 'Laravel Octane')}
                            value={octane?.installed
                                ? (octane.server || t('admin.performance.on', 'On'))
                                : t('admin.performance.off', 'Off')}
                            tone={octane?.installed ? 'success' : 'warning'}
                        />
                        <StatusRow
                            label={t('admin.performance.redis_client', 'Redis client')}
                            value={stack?.redis_client || '—'}
                            tone={stack?.redis_client === 'predis' ? 'success' : 'neutral'}
                        />
                        <StatusRow
                            label={t('admin.performance.queue_connection', 'Queue')}
                            value={stack?.queue_connection || '—'}
                        />
                        <StatusRow
                            label={t('admin.performance.object_storage', 'Object storage')}
                            value={objectStorage?.driver
                                ? `${objectStorage.driver}${objectStorage.package_ready ? '' : ' (SDK missing)'}`
                                : 'local'}
                            tone={objectStorage?.package_ready ? 'success' : 'warning'}
                        />
                        <StatusRow
                            label={t('admin.performance.webp', 'WebP images')}
                            value={webp ? t('admin.performance.on', 'On') : t('admin.performance.off', 'Off')}
                            tone={webp ? 'success' : 'warning'}
                        />
                        <StatusRow
                            label={t('admin.performance.images_missing', 'Images missing a size')}
                            value={imagesMissing >= 501 ? '500+' : String(imagesMissing)}
                            tone={imagesMissing > 0 ? 'warning' : 'success'}
                        />
                        <StatusRow
                            label={t('admin.performance.search', 'Product search')}
                            value={search === 'meilisearch'
                                ? t('admin.performance.search_meili', 'Meilisearch')
                                : t('admin.performance.search_db', 'Database')}
                        />
                        <div className="pt-2">
                            <Link href="/admin/images" className="text-sm font-medium text-violet-700 hover:text-violet-800">
                                {t('admin.performance.open_images', 'Image settings')}
                            </Link>
                        </div>
                        {cacheDriver === 'file' || cacheDriver === 'database' ? (
                            <p className={cx(ui.muted, 'pt-3')}>
                                {t('admin.performance.redis_note', 'For production scale use CACHE_STORE=redis, SESSION_DRIVER=redis, QUEUE_CONNECTION=redis, and WENTO_SERVER=octane.')}
                            </p>
                        ) : null}
                    </Card>
                </aside>
            </div>
        </Layout>
    );
}
