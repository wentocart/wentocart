import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Layout from '../Layout';
import {
    Button,
    Card,
    FeatureTile,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    SettingsCount,
    SettingsNote,
    SettingsSectionHead,
    Toggle,
    Tooltip,
    t,
    cx,
} from '../../ui';

function SearchRuleRow({ columns, children, actions }) {
    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div className={cx('grid min-w-0 flex-1 gap-3', columns)}>{children}</div>
            <div className="flex h-11 shrink-0 items-center gap-4">{actions}</div>
        </div>
    );
}

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.search_catalog', 'Search & Catalog') },
];

/** Fallback mirrors SearchCatalogSettings::SORT_OPTIONS when props omit sort_options. */
const DEFAULT_SORT_OPTIONS = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'popularity', label: 'Popularity' },
    { value: 'newest', label: 'Newest' },
    { value: 'price_asc', label: 'Price: Low to High' },
    { value: 'price_desc', label: 'Price: High to Low' },
    { value: 'rating', label: 'Rating' },
    { value: 'discount', label: 'Biggest Discount' },
];

const SORT_LABEL_KEYS = {
    relevance: 'admin.search_catalog.sort_relevance',
    popularity: 'admin.search_catalog.sort_popular',
    newest: 'admin.search_catalog.sort_newest',
    price_asc: 'admin.search_catalog.sort_price_asc',
    price_desc: 'admin.search_catalog.sort_price_desc',
    rating: 'admin.search_catalog.sort_rating',
    discount: 'admin.search_catalog.sort_discount',
};

export default function SearchCatalog(props) {
    const sortOptions = Array.isArray(props.sort_options) && props.sort_options.length
        ? props.sort_options
        : DEFAULT_SORT_OPTIONS;
    const form = useForm({
        default_sort: props.default_sort || 'popularity',
        per_page: Number(props.per_page ?? 24),
        suggestions: props.suggestions !== false,
        typo_tolerance: !!props.typo_tolerance,
        recently_viewed: props.recently_viewed !== false,
        wishlist_enabled: props.wishlist_enabled !== false,
        engine: props.engine || 'database',
        meilisearch: !!props.meilisearch,
        meilisearch_host: props.meilisearch_host || '',
        meilisearch_key: props.meilisearch_key || '',
        meilisearch_index: props.meilisearch_index || 'products',
        elasticsearch: !!props.elasticsearch,
        elasticsearch_host: props.elasticsearch_host || '',
        elasticsearch_user: props.elasticsearch_user || '',
        elasticsearch_pass: props.elasticsearch_pass || '',
        elasticsearch_index: props.elasticsearch_index || 'products',
        synonyms: props.synonyms?.length
            ? props.synonyms.map((row) => ({ term: row.term || '', aliases: row.aliases || '', is_enabled: row.is_enabled !== false }))
            : [{ term: '', aliases: '', is_enabled: true }],
        pins: props.pins?.length
            ? props.pins.map((row) => ({ query: row.query || '', product_id: row.product_id || '', position: row.position ?? 0 }))
            : [{ query: '', product_id: '', position: 0 }],
        redirects: props.redirects?.length
            ? props.redirects.map((row) => ({ query: row.query || '', target_path: row.target_path || '', is_enabled: row.is_enabled !== false }))
            : [{ query: '', target_path: '', is_enabled: true }],
    });
    const formRef = useRef(form);
    formRef.current = form;
    const booted = useRef(false);
    const lastData = useRef(form.data);
    const lastSent = useRef('');
    const saveTimer = useRef(null);
    const textKeys = new Set([
        'per_page',
        'engine',
        'meilisearch_host',
        'meilisearch_key',
        'meilisearch_index',
        'elasticsearch_host',
        'elasticsearch_user',
        'elasticsearch_pass',
        'elasticsearch_index',
        'synonyms',
        'pins',
        'redirects',
    ]);
    const engineOptions = [
        { value: 'database', label: t('admin.search_catalog.engine_database', 'Database (LIKE / FULLTEXT)') },
        { value: 'mysql_fulltext', label: t('admin.search_catalog.engine_mysql', 'MySQL FULLTEXT') },
        { value: 'meilisearch', label: t('admin.search_catalog.engine_meili', 'Meilisearch') },
        { value: 'elasticsearch', label: t('admin.search_catalog.engine_elastic', 'Elasticsearch') },
    ];

    useEffect(() => {
        const snapshot = JSON.stringify(form.data);
        if (!booted.current) {
            booted.current = true;
            lastData.current = form.data;
            lastSent.current = snapshot;

            return undefined;
        }
        if (snapshot === lastSent.current) {
            return undefined;
        }

        const previous = lastData.current || {};
        lastData.current = form.data;
        const changed = Object.keys(form.data).filter((key) => JSON.stringify(form.data[key]) !== JSON.stringify(previous[key]));
        const textOnly = changed.length > 0 && changed.every((key) => textKeys.has(key));

        if (saveTimer.current) {
            clearTimeout(saveTimer.current);
        }
        saveTimer.current = setTimeout(() => {
            const current = formRef.current;
            lastSent.current = JSON.stringify(current.data);
            current.transform((data) => ({
                ...data,
                synonyms: (data.synonyms || []).filter((row) => String(row.term || '').trim() !== ''),
                pins: (data.pins || []).filter((row) => String(row.query || '').trim() !== '' && String(row.product_id || '').trim() !== ''),
                redirects: (data.redirects || []).filter((row) => String(row.query || '').trim() !== '' && String(row.target_path || '').trim() !== ''),
            }));
            current.put('/admin/search-settings', {
                preserveScroll: true,
                preserveState: true,
            });
        }, textOnly ? 1000 : 0);

        return () => {
            if (saveTimer.current) {
                clearTimeout(saveTimer.current);
            }
        };
    }, [form.data]);

    const body = (
            <form className="space-y-6" onSubmit={(e) => {
                e.preventDefault();
            }}>
                <FormErrors errors={form.errors} />
                <Card>
                    <SettingsSectionHead
                        icon="search"
                        title={t('admin.search_catalog.listing', 'Listing')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="search"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.search_catalog.sort', 'Default sorting')}
                            hint={t('admin.search_catalog.sort_hint', 'Order used when a shopper has not chosen a sort.')}
                            control={(
                                <Select className="w-48 shrink-0" value={form.data.default_sort} onChange={(e) => form.setData('default_sort', e.target.value)}>
                                    {sortOptions.map((row) => (
                                        <option key={row.value} value={row.value}>
                                            {t(SORT_LABEL_KEYS[row.value] || `admin.search_catalog.sort_${row.value}`, row.label)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="layers"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.search_catalog.per_page', 'Products per page')}
                            hint={t('admin.search_catalog.per_page_hint', 'Catalog page size. 8 to 48.')}
                            control={(
                                <Input className="w-24 shrink-0" type="number" min="8" max="48" value={form.data.per_page} onChange={(e) => form.setData('per_page', Number(e.target.value))} />
                            )}
                        />
                        <FeatureTile
                            icon="sparkles"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.search_catalog.suggestions', 'Search suggestions')}
                            hint={t('admin.search_catalog.suggestions_hint', 'Offer matching terms as the shopper types.')}
                            value={!!form.data.suggestions}
                            onChange={(on) => form.setData('suggestions', on)}
                        />
                        <FeatureTile
                            icon="refresh"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.search_catalog.typo', 'Typo tolerance')}
                            hint={t('admin.search_catalog.typo_hint', 'Still find products when the query has a small typo.')}
                            value={!!form.data.typo_tolerance}
                            onChange={(on) => form.setData('typo_tolerance', on)}
                        />
                    </div>
                </Card>
                <Card>
                    <SettingsSectionHead
                        icon="search"
                        title={t('admin.search_catalog.engines', 'Search Engines')}
                        hint={t('admin.search_catalog.engines_hint', 'Pick the preferred engine. Unavailable engines fall through automatically.')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 9 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="layers"
                            tone="bg-slate-100 text-slate-700"
                            title={t('admin.search_catalog.preferred_engine', 'Preferred engine')}
                            hint={t('admin.search_catalog.preferred_engine_hint', 'Meilisearch and Elasticsearch need a host. Database always works.')}
                            control={(
                                <Select
                                    className="w-56 shrink-0"
                                    value={form.data.engine}
                                    onChange={(e) => form.setData('engine', e.target.value)}
                                >
                                    {engineOptions.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="search"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.search_catalog.meilisearch_on', 'Enable Meilisearch')}
                            hint={t('admin.search_catalog.meilisearch_on_hint', 'Falls back when the host is empty or unreachable.')}
                            value={!!form.data.meilisearch}
                            onChange={(on) => form.setData('meilisearch', on)}
                        />
                        <FeatureTile
                            icon="link"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.search_catalog.meilisearch_host', 'Meilisearch host')}
                            hint={t('admin.search_catalog.meilisearch_host_hint', 'Example: http://127.0.0.1:7700')}
                            control={(
                                <Input className="w-56 shrink-0" placeholder="http://127.0.0.1:7700" value={form.data.meilisearch_host} onChange={(e) => form.setData('meilisearch_host', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="lock"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.search_catalog.meilisearch_key', 'Meilisearch API key')}
                            hint={t('admin.search_catalog.meilisearch_key_hint', 'Search or admin key from your Meilisearch instance.')}
                            control={(
                                <Input className="w-48 shrink-0" value={form.data.meilisearch_key} onChange={(e) => form.setData('meilisearch_key', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="tag"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.search_catalog.meilisearch_index', 'Meilisearch index')}
                            hint={t('admin.search_catalog.meilisearch_index_hint', 'Index name on the Meilisearch server.')}
                            control={(
                                <Input className="w-40 shrink-0" value={form.data.meilisearch_index} onChange={(e) => form.setData('meilisearch_index', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="database"
                            tone="bg-indigo-100 text-indigo-600"
                            title={t('admin.search_catalog.elasticsearch_on', 'Enable Elasticsearch')}
                            hint={t('admin.search_catalog.elasticsearch_on_hint', 'Optional Elasticsearch engine over HTTP.')}
                            value={!!form.data.elasticsearch}
                            onChange={(on) => form.setData('elasticsearch', on)}
                        />
                        <FeatureTile
                            icon="link"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.search_catalog.elasticsearch_host', 'Elasticsearch host')}
                            hint={t('admin.search_catalog.elasticsearch_host_hint', 'Example: http://127.0.0.1:9200')}
                            control={(
                                <Input className="w-56 shrink-0" placeholder="http://127.0.0.1:9200" value={form.data.elasticsearch_host} onChange={(e) => form.setData('elasticsearch_host', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="user"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.search_catalog.elasticsearch_user', 'Elasticsearch user')}
                            control={(
                                <Input className="w-40 shrink-0" value={form.data.elasticsearch_user} onChange={(e) => form.setData('elasticsearch_user', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="lock"
                            tone="bg-rose-100 text-rose-600"
                            title={t('admin.search_catalog.elasticsearch_pass', 'Elasticsearch password')}
                            control={(
                                <Input className="w-40 shrink-0" type="password" value={form.data.elasticsearch_pass} onChange={(e) => form.setData('elasticsearch_pass', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="tag"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.search_catalog.elasticsearch_index', 'Elasticsearch index')}
                            control={(
                                <Input className="w-40 shrink-0" value={form.data.elasticsearch_index} onChange={(e) => form.setData('elasticsearch_index', e.target.value)} />
                            )}
                        />
                    </div>
                </Card>
                <Card>
                    <SettingsSectionHead
                        icon="star"
                        title={t('admin.search_catalog.experience', 'Experience')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 2 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="clock"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.search_catalog.recent', 'Recently viewed')}
                            hint={t('admin.search_catalog.recent_hint', 'Remember products the shopper opened.')}
                            value={!!form.data.recently_viewed}
                            onChange={(on) => form.setData('recently_viewed', on)}
                        />
                        <FeatureTile
                            icon="heart"
                            tone="bg-rose-100 text-rose-600"
                            title={t('admin.search_catalog.wishlist', 'Wishlist')}
                            hint={t('admin.search_catalog.wishlist_hint', 'Let shoppers save products for later.')}
                            value={!!form.data.wishlist_enabled}
                            onChange={(on) => form.setData('wishlist_enabled', on)}
                        />
                    </div>
                    <SettingsNote>
                        {t('admin.search_catalog.compare_note', 'Product comparison is configured on Product Settings.')}
                        {props.embedded ? null : (
                            <>
                                {' '}
                                <Button type="button" variant="link" href="/admin/product-settings" className="px-0">
                                    {t('admin.nav.product_settings', 'Product Settings')}
                                </Button>
                            </>
                        )}
                    </SettingsNote>
                </Card>
                <Card className="space-y-4">
                    <SettingsSectionHead icon="search" title={t('admin.search_catalog.synonyms', 'Synonyms')} hint={t('admin.search_catalog.synonyms_hint', 'Expand a search term to aliases (comma-separated).')} />
                    {(form.data.synonyms || []).map((row, index) => (
                        <SearchRuleRow key={index} columns="sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]" actions={(
                            <>
                                <Toggle ariaLabel={t('admin.common.active', 'Active')} value={!!row.is_enabled} onChange={(on) => {
                                    const next = form.data.synonyms.map((item, i) => (i === index ? { ...item, is_enabled: on } : item));
                                    form.setData('synonyms', next);
                                }} />
                                <Tooltip content={t('admin.search_catalog.remove_synonym', 'Remove synonym')}>
                                    <Button
                                        type="button"
                                        variant="danger"
                                        icon="trash"
                                        aria-label={t('admin.search_catalog.remove_synonym', 'Remove synonym')}
                                        onClick={() => form.setData('synonyms', form.data.synonyms.filter((_, i) => i !== index))}
                                    />
                                </Tooltip>
                            </>
                        )}>
                            <Field label={t('admin.search_catalog.term', 'Term')}>
                                <Input wide value={row.term} onChange={(e) => {
                                    const next = form.data.synonyms.map((item, i) => (i === index ? { ...item, term: e.target.value } : item));
                                    form.setData('synonyms', next);
                                }} />
                            </Field>
                            <Field label={t('admin.search_catalog.aliases', 'Aliases')}>
                                <Input wide value={row.aliases} onChange={(e) => {
                                    const next = form.data.synonyms.map((item, i) => (i === index ? { ...item, aliases: e.target.value } : item));
                                    form.setData('synonyms', next);
                                }} />
                            </Field>
                        </SearchRuleRow>
                    ))}
                    <Button type="button" variant="secondary" icon="plus" onClick={() => form.setData('synonyms', [...form.data.synonyms, { term: '', aliases: '', is_enabled: true }])}>
                        {t('admin.search_catalog.add_synonym', 'Add synonym')}
                    </Button>
                </Card>
                <Card className="space-y-4">
                    <SettingsSectionHead icon="star" title={t('admin.search_catalog.pins', 'Merchandising pins')} hint={t('admin.search_catalog.pins_hint', 'Pin a product to the top of page 1 for an exact search query.')} />
                    {(form.data.pins || []).map((row, index) => (
                        <SearchRuleRow key={index} columns="sm:grid-cols-[minmax(0,1fr)_7.5rem_6.5rem]" actions={(
                            <Tooltip content={t('admin.search_catalog.remove_pin', 'Remove pin')}>
                                <Button
                                    type="button"
                                    variant="danger"
                                    icon="trash"
                                    aria-label={t('admin.search_catalog.remove_pin', 'Remove pin')}
                                    onClick={() => form.setData('pins', form.data.pins.filter((_, i) => i !== index))}
                                />
                            </Tooltip>
                        )}>
                            <Field label={t('admin.search_catalog.query', 'Query')}>
                                <Input wide value={row.query} onChange={(e) => {
                                    const next = form.data.pins.map((item, i) => (i === index ? { ...item, query: e.target.value } : item));
                                    form.setData('pins', next);
                                }} />
                            </Field>
                            <Field label={t('admin.search_catalog.product_id', 'Product ID')}>
                                <Input wide type="number" min="1" value={row.product_id} onChange={(e) => {
                                    const next = form.data.pins.map((item, i) => (i === index ? { ...item, product_id: e.target.value } : item));
                                    form.setData('pins', next);
                                }} />
                            </Field>
                            <Field label={t('admin.search_catalog.position', 'Position')}>
                                <Input wide type="number" min="0" value={row.position} onChange={(e) => {
                                    const next = form.data.pins.map((item, i) => (i === index ? { ...item, position: Number(e.target.value) } : item));
                                    form.setData('pins', next);
                                }} />
                            </Field>
                        </SearchRuleRow>
                    ))}
                    <Button type="button" variant="secondary" icon="plus" onClick={() => form.setData('pins', [...form.data.pins, { query: '', product_id: '', position: 0 }])}>
                        {t('admin.search_catalog.add_pin', 'Add pin')}
                    </Button>
                </Card>
                <Card className="space-y-4">
                    <SettingsSectionHead icon="search" title={t('admin.search_catalog.redirects', 'Search redirects')} hint={t('admin.search_catalog.redirects_hint', 'Send a search term to a category or page path, e.g. /catalog/shoes.')} />
                    {(form.data.redirects || []).map((row, index) => (
                        <SearchRuleRow key={index} columns="sm:grid-cols-2" actions={(
                            <>
                                <Toggle label={t('admin.common.active', 'Active')} value={!!row.is_enabled} onChange={(on) => {
                                    const next = form.data.redirects.map((item, i) => (i === index ? { ...item, is_enabled: on } : item));
                                    form.setData('redirects', next);
                                }} />
                                <Button type="button" variant="danger" className="whitespace-nowrap" onClick={() => form.setData('redirects', form.data.redirects.filter((_, i) => i !== index))}>{t('admin.common.remove', 'Remove')}</Button>
                            </>
                        )}>
                            <Field label={t('admin.search_catalog.query', 'Query')}>
                                <Input wide value={row.query} onChange={(e) => {
                                    const next = form.data.redirects.map((item, i) => (i === index ? { ...item, query: e.target.value } : item));
                                    form.setData('redirects', next);
                                }} />
                            </Field>
                            <Field label={t('admin.search_catalog.target', 'Target path')}>
                                <Input wide value={row.target_path} onChange={(e) => {
                                    const next = form.data.redirects.map((item, i) => (i === index ? { ...item, target_path: e.target.value } : item));
                                    form.setData('redirects', next);
                                }} />
                            </Field>
                        </SearchRuleRow>
                    ))}
                    <Button type="button" variant="secondary" icon="plus" onClick={() => form.setData('redirects', [...(form.data.redirects || []), { query: '', target_path: '', is_enabled: true }])}>
                        {t('admin.search_catalog.add_redirect', 'Add redirect')}
                    </Button>
                </Card>
            </form>
    );

    if (props.embedded) {
        return body;
    }

    return (
        <Layout>
            <PageHeader
                title={t('admin.search_catalog.title', 'Search & Catalog')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.search_catalog.subtitle', 'Storefront listing and discovery. Compare lives on Product Settings. Out-of-stock visibility is also owned there.')}
            />
            {body}
        </Layout>
    );
}
