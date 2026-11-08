import { router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import Editor from '../../ui/Editor';
import {
    Alert,
    Badge,
    Button,
    Card,
    ColumnFilter,
    EmptyState,
    Field,
    FeatureTile,
    FilterReset,
    FormErrors,
    Icon,
    Input,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    Select,
    SettingsSectionHead,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Toggle,
    ui,
    t,
    cx,
} from '../../ui';
import { AdvancedClubTabs } from './clubTabs';
import { adminUrl } from '../../webPath';

function copyText(value) {
    const text = value == null ? '' : String(value);
    if (!text || !navigator?.clipboard?.writeText) {
        return;
    }
    navigator.clipboard.writeText(text).catch(() => {});
}

function formatSql(sql) {
    const raw = String(sql || '').trim();
    if (!raw) {
        return '';
    }

    return raw
        .replace(/\s+/g, ' ')
        .replace(/\s*,\s*/g, ',\n  ')
        .replace(/\bSELECT\b/i, 'SELECT')
        .replace(/\bFROM\b/i, '\nFROM')
        .replace(/\bWHERE\b/i, '\nWHERE')
        .replace(/\bAND\b/gi, '\n  AND')
        .replace(/\bOR\b/gi, '\n  OR')
        .replace(/\bORDER BY\b/i, '\nORDER BY')
        .replace(/\bGROUP BY\b/i, '\nGROUP BY')
        .replace(/\bLIMIT\b/i, '\nLIMIT')
        .trim();
}

function ProfilerTab({ enabled, queries }) {
    const form = useForm({ enabled: !!enabled });

    useSaveOnChange(form, '/admin/advanced/profiler');

    return (
        <div className="space-y-4">
            <Card
                as="form"
                className="max-w-2xl space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                }}
            >
                <SettingsSectionHead
                    icon="gauge"
                    title={t('admin.advanced.profiler', 'Profiler')}
                    hint={t('admin.advanced.profiler_lead', 'Record time, memory, and SQL for recent requests.')}
                />
                <FeatureTile
                    icon="gauge"
                    tone="bg-violet-100 text-violet-600"
                    title={t('admin.advanced.profiler', 'Profiler')}
                    hint={t('admin.advanced.profiler_hint', 'When on, the shop stores time, memory, and SQL for recent requests. Leave it off on a live shop. It slows every page.')}
                    value={form.data.enabled}
                    onChange={(on) => form.setData('enabled', on)}
                />
            </Card>
            {!queries?.length ? (
                <Card className="max-w-2xl">
                    <p className={ui.muted}>
                        {t('admin.advanced.no_profiler_hint', 'Turn the profiler on, then browse the shop or admin. Time, memory, and SQL for recent requests appear here.')}
                    </p>
                </Card>
            ) : (
                queries.map((row, index) => (
                    <Card key={`${row.at}-${index}`} className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge tone="info" dot={false}>{row.method}</Badge>
                            <span className="text-sm font-medium break-all">{row.url}</span>
                        </div>
                        <p className={ui.muted}>
                            {row.time_ms} ms · {row.query_count} {t('admin.advanced.sql_statements', 'SQL statements')} · {row.memory_kb} KB
                        </p>
                        <ListPanel>
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('admin.advanced.sql', 'SQL')}</Th>
                                        <Th>{t('admin.advanced.ms', 'ms')}</Th>
                                    </Tr>
                                </THead>
                                <tbody>
                                    {(row.queries || []).map((q, i) => (
                                        <Tr key={i}>
                                            <Td><code className="text-xs break-all">{q.sql}</code></Td>
                                            <Td>{q.time}</Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </ListPanel>
                    </Card>
                ))
            )}
        </div>
    );
}

function QueriesTab({ tables = [], saved = [], result, draft_sql: draftSql }) {
    const form = useForm({
        name: '',
        sql: draftSql || '',
        saved_id: '',
    });
    const [tableSearch, setTableSearch] = useState('');
    const [selectedTable, setSelectedTable] = useState(() => tables[0]?.name || '');

    const filteredTables = useMemo(() => {
        const q = tableSearch.trim().toLowerCase();
        if (!q) {
            return tables;
        }

        return tables.filter((table) => String(table.name || '').toLowerCase().includes(q));
    }, [tables, tableSearch]);

    const activeTable = selectedTable || tables[0]?.name || '';
    const lineCount = Math.max(1, String(form.data.sql || '').split('\n').length);
    const sampleTable = activeTable || 'wento_admins';
    const sqlPlaceholder = `SELECT * FROM ${sampleTable} WHERE ...`;

    const run = (sql, savedId) => {
        form.setData({ name: form.data.name, sql, saved_id: savedId || '' });
        form.transform(() => ({ sql, saved_id: savedId || '' }));
        form.post('/admin/advanced/queries/run', { preserveScroll: true });
    };

    const save = () => {
        form.transform((data) => ({ name: data.name, sql: data.sql }));
        form.post('/admin/advanced/queries', { preserveScroll: true });
    };

    return (
        <div className="space-y-4">
            <div className={ui.queryLayout}>
                <Card className="space-y-3">
                    <div className="flex items-center gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="table" className="h-5 w-5" />
                        </span>
                        <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.tables', 'Tables')}</h2>
                    </div>
                    <div className="relative">
                        <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <Input
                            className="w-full pl-9"
                            value={tableSearch}
                            placeholder={t('admin.advanced.search_tables', 'Search tables...')}
                            onChange={(e) => setTableSearch(e.target.value)}
                        />
                    </div>
                    {!filteredTables.length ? (
                        <p className={ui.muted}>{t('admin.advanced.no_tables', 'No tables found.')}</p>
                    ) : (
                        <div className={ui.queryTableList}>
                            {filteredTables.map((table) => {
                                const active = activeTable === table.name;
                                return (
                                    <button
                                        key={table.name}
                                        type="button"
                                        className={active ? ui.queryTableBtnActive : ui.queryTableBtn}
                                        onClick={() => {
                                            setSelectedTable(table.name);
                                            form.setData({ ...form.data, sql: table.sql, saved_id: '' });
                                        }}
                                    >
                                        <Icon name="table" className={active ? ui.queryTableIconActive : ui.queryTableIcon} />
                                        <span className="truncate">{table.name}</span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    <div className="flex items-center justify-between gap-2 border-t border-neutral-100 pt-3">
                        <span className={ui.muted}>
                            {tables.length} {t('admin.advanced.tables_count', 'tables')}
                        </span>
                        <Button
                            type="button"
                            variant="secondary"
                            icon="refresh"
                            onClick={() => router.reload({ only: ['tables'] })}
                        >
                            {t('admin.common.refresh', 'Refresh')}
                        </Button>
                    </div>
                </Card>

                <Card className="space-y-4 min-w-0">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="database" className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.queries', 'DB queries')}</h2>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.queries_hint', 'Run a SELECT query to inspect shop data. Insert, update, delete, and schema changes are blocked for safety.')}
                            </p>
                        </div>
                    </div>
                    <FormErrors errors={form.errors} />
                    <div>
                        <div className={cx(ui.label, 'mb-1.5')}>{t('admin.advanced.sql_query', 'SQL query')}</div>
                        <div className={ui.sqlEditorWrap}>
                            <div className={ui.sqlEditorToolbar}>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    icon="wand"
                                    onClick={() => form.setData('sql', formatSql(form.data.sql))}
                                >
                                    {t('admin.advanced.format', 'Format')}
                                </Button>
                            </div>
                            <div className={ui.sqlEditorInner}>
                                <div className={ui.sqlLineGutter} aria-hidden="true">
                                    {Array.from({ length: lineCount }, (_, index) => (
                                        <div key={index}>{index + 1}</div>
                                    ))}
                                </div>
                                <textarea
                                    rows={Math.max(10, lineCount)}
                                    className={cx(ui.sqlTextarea, form.errors.sql ? 'text-rose-700' : null)}
                                    value={form.data.sql}
                                    onChange={(e) => form.setData('sql', e.target.value)}
                                    spellCheck="false"
                                    placeholder={sqlPlaceholder}
                                    aria-label={t('admin.advanced.sql_query', 'SQL query')}
                                />
                            </div>
                        </div>
                        {form.errors.sql ? <p className={ui.fieldError}>{form.errors.sql}</p> : null}
                    </div>
                    <Field
                        label={t('admin.advanced.query_name_optional', 'Query name (optional)')}
                        hint={t('admin.advanced.query_name_hint', 'Used when you save the query for later.')}
                        error={form.errors.name}
                    >
                        <Input
                            wide
                            value={form.data.name}
                            placeholder={t('admin.advanced.query_name_example', 'E.g. Admin list')}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                    </Field>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" icon="play" busy={form.processing} onClick={() => run(form.data.sql, '')}>
                            {t('admin.advanced.run_query', 'Run query')}
                        </Button>
                        <Button type="button" variant="secondary" icon="save" busy={form.processing} onClick={save}>
                            {t('admin.advanced.save_query', 'Save query')}
                        </Button>
                    </div>
                </Card>
            </div>

            <section className="space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3 min-w-0">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="file" className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.saved_queries', 'Saved queries')}</h2>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.saved_queries_hint', 'Saved SELECT statements appear here. Click a row to run it again.')}
                            </p>
                        </div>
                    </div>
                </div>
                {!saved.length ? (
                    <div className={ui.emptyPanel}>
                        <span className={ui.emptyPanelIcon}>
                            <Icon name="folder" className="h-7 w-7" />
                        </span>
                        <p className={cx(ui.muted, 'max-w-md mb-0')}>
                            {t('admin.advanced.no_saved_queries_yet', 'No saved queries yet. Run a query and save it for quick access later.')}
                        </p>
                    </div>
                ) : (
                    <ListPanel>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('admin.advanced.query_name', 'Name')}</Th>
                                    <Th>{t('admin.advanced.sql', 'SQL')}</Th>
                                    <Th>{t('admin.advanced.last_run', 'Last run')}</Th>
                                    <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                                </Tr>
                            </THead>
                            <tbody>
                                {saved.map((row) => (
                                    <Tr key={row.id} onClick={() => run(row.sql, row.id)}>
                                        <Td>{row.name}</Td>
                                        <Td><code className="text-xs break-all">{row.sql}</code></Td>
                                        <Td>{row.last_run_at || '—'}</Td>
                                        <Td actions>
                                            <Button
                                                type="button"
                                                variant="danger"
                                                icon="trash"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    router.delete(`/admin/advanced/queries/${row.id}`, { preserveScroll: true });
                                                }}
                                            >
                                                {t('admin.common.delete', 'Delete')}
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </ListPanel>
                )}
            </section>

            {result ? (
                <Card className="space-y-3">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="bar-chart" className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.results', 'Results')}</h2>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {result.row_count} {t('admin.advanced.rows', 'rows')}
                                {result.truncated ? ` · ${t('admin.advanced.truncated', 'showing the first 100')}` : ''}
                            </p>
                        </div>
                    </div>
                    {!result.columns?.length ? (
                        <p className={ui.muted}>{t('admin.advanced.no_rows', 'The query returned no rows.')}</p>
                    ) : (
                        <div className={ui.queryResultWrap}>
                            <Table>
                                <THead>
                                    <Tr>
                                        {result.columns.map((col) => (
                                            <Th key={col}>{col}</Th>
                                        ))}
                                    </Tr>
                                </THead>
                                <tbody>
                                    {result.rows.map((row, index) => (
                                        <Tr key={index}>
                                            {result.columns.map((col) => (
                                                <Td key={col}>
                                                    <code className="text-xs break-all">{row[col] === null || row[col] === undefined ? 'NULL' : String(row[col])}</code>
                                                </Td>
                                            ))}
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    )}
                </Card>
            ) : null}
        </div>
    );
}

function FileLogTab({ rows, filters = {} }) {
    const query = useMemo(() => ({
        tab: 'files',
        action: filters.action || '',
        path: filters.path || '',
        date: filters.date || '',
        sort: filters.sort || 'created_at',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next, tab: 'files' };
        Object.keys(payload).forEach((key) => {
            if (payload[key] === '' || payload[key] == null) {
                delete payload[key];
            }
        });
        payload.tab = 'files';
        router.get('/admin/advanced', payload, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((name, value) => {
        apply({ [name]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({
            sort: column,
            dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc',
        });
    }, [apply, query.dir, query.sort]);

    const data = rows?.data || [];

    return (
        <div className="space-y-4">
            <div>
                <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.file_logs', 'File changes')}</h2>
                <p className={cx(ui.muted, 'mt-1')}>
                    {t('admin.advanced.file_logs_lead', 'Track changes made to theme, template, and system files.')}
                </p>
            </div>

            <ListPanel
                footer={<ListFooter pagination={rows} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <SortHeader column="action" label={t('admin.advanced.action', 'Action')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="path" label={t('admin.advanced.path', 'Path')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.advanced.when', 'When')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="action" value={query.action} placeholder={t('admin.advanced.search_action', 'Search action')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="path" value={query.path} placeholder={t('admin.advanced.search_by_path', 'Search by path...')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="date" type="date" value={query.date} onApply={applyColumn} />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/advanced?tab=files" ignore={['tab', 'sort', 'dir', 'page', 'per_page']} />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {!data.length ? (
                            <EmptyState colSpan={4}>
                                {t('admin.advanced.no_file_logs_hint', 'Email HTML, URL schemas, image regenerate, and cache clears are listed here.')}
                            </EmptyState>
                        ) : (
                            data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="capitalize">{row.action}</Td>
                                    <Td><code className="text-xs">{row.path}</code></Td>
                                    <Td>{row.created_at || '—'}</Td>
                                    <Td actions>
                                        <RowActions
                                            canEdit={false}
                                            canDelete={false}
                                            items={[
                                                {
                                                    key: 'copy',
                                                    icon: 'copy',
                                                    label: t('admin.advanced.copy_path', 'Copy path'),
                                                    onClick: () => copyText(row.path),
                                                },
                                            ]}
                                        />
                                    </Td>
                                </Tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </ListPanel>
        </div>
    );
}

function InfoTab({ info }) {
    const rows = [
        {
            key: 'php',
            icon: 'code',
            label: t('admin.advanced.php_version', 'PHP version'),
            hint: t('admin.advanced.php_hint', 'Current PHP version on the server'),
            value: info.php,
        },
        {
            key: 'laravel',
            icon: 'package',
            label: t('admin.advanced.laravel_version', 'Laravel version'),
            hint: t('admin.advanced.laravel_hint', 'Framework version'),
            value: info.laravel,
        },
        {
            key: 'shop_name',
            icon: 'bag',
            label: t('admin.advanced.shop_name', 'Shop name'),
            hint: t('admin.advanced.shop_name_hint', 'Your store name'),
            value: info.shop_name,
        },
        {
            key: 'shop_url',
            icon: 'globe',
            label: t('admin.advanced.shop_url', 'Shop URL'),
            hint: t('admin.advanced.shop_url_hint', 'Public URL of your store'),
            value: info.shop_url,
            link: true,
        },
        {
            key: 'multishop',
            icon: 'layers',
            label: t('admin.advanced.multishop', 'Multishop'),
            hint: t('admin.advanced.multishop_hint', 'Multishop feature'),
            value: info.multishop,
            badge: true,
        },
        {
            key: 'database',
            icon: 'archive',
            label: t('admin.advanced.database', 'Database'),
            hint: t('admin.advanced.database_hint', 'Database name'),
            value: info.database,
        },
        {
            key: 'prefix',
            icon: 'tag',
            label: t('admin.advanced.prefix', 'Table prefix'),
            hint: t('admin.advanced.prefix_hint', 'Prefix for database tables'),
            value: info.prefix || t('admin.advanced.none', 'None'),
        },
        {
            key: 'cache',
            icon: 'zap',
            label: t('admin.advanced.cache_driver', 'Cache driver'),
            hint: t('admin.advanced.cache_hint', 'Cache storage driver'),
            value: info.cache,
        },
        {
            key: 'mailer',
            icon: 'mail',
            label: t('admin.advanced.mailer', 'Mailer'),
            hint: t('admin.advanced.mailer_hint', 'Email driver'),
            value: info.mailer,
        },
        {
            key: 'disk',
            icon: 'folder',
            label: t('admin.advanced.disk', 'Free disk space'),
            hint: t('admin.advanced.disk_hint', 'Available storage space'),
            value: info.disk_free,
        },
        {
            key: 'mysql',
            icon: 'settings',
            label: t('admin.advanced.mysql', 'Database version'),
            hint: t('admin.advanced.mysql_hint', 'MySQL / MariaDB version'),
            value: info.mysql,
        },
        {
            key: 'gd',
            icon: 'photo',
            label: t('admin.advanced.gd', 'GD'),
            hint: t('admin.advanced.gd_hint', 'GD library status'),
            value: info.gd,
            badge: true,
        },
    ];

    return (
        <div className={ui.formLayout}>
            <div className={ui.formMain}>
                <Card className="space-y-1">
                    <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                        <div className="flex items-start gap-3">
                            <span className={ui.sectionHeadIcon}>
                                <Icon name="bag" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.info', 'Shop information')}</h2>
                                <p className={cx(ui.muted, 'mt-1')}>
                                    {t('admin.advanced.info_lead', 'View the current configuration of your shop.')}
                                </p>
                            </div>
                        </div>
                        <Button variant="secondary" icon="pencil" href="/admin/store">
                            {t('admin.advanced.edit_information', 'Edit information')}
                        </Button>
                    </div>
                    {rows.map((row) => {
                        const display = row.badge
                            ? (row.value ? t('admin.advanced.on', 'On') : t('admin.advanced.off', 'Off'))
                            : (row.value || '—');
                        return (
                            <div key={row.key} className={ui.infoRow}>
                                <div className="flex items-start gap-3 min-w-0">
                                    <span className={ui.infoRowIcon}>
                                        <Icon name={row.icon} className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0">
                                        <div className={ui.infoRowLabel}>{row.label}</div>
                                        <div className={ui.infoRowHint}>{row.hint}</div>
                                    </div>
                                </div>
                                <div className={ui.infoRowActions}>
                                    {row.badge ? (
                                        <Badge tone={row.value ? 'success' : 'neutral'} dot={false}>{display}</Badge>
                                    ) : row.link && row.value ? (
                                        <a
                                            href={row.value}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-sm font-medium text-violet-700 hover:underline break-all"
                                        >
                                            {row.value}
                                        </a>
                                    ) : (
                                        <div className={ui.infoRowValue}>{display}</div>
                                    )}
                                    {row.link && row.value ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            icon="link"
                                            native
                                            aria-label={t('admin.advanced.open_url', 'Open URL')}
                                            href={row.value}
                                            target="_blank"
                                            rel="noreferrer"
                                        />
                                    ) : null}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        icon="copy"
                                        aria-label={t('admin.common.copy', 'Copy')}
                                        onClick={() => copyText(row.badge ? display : row.value)}
                                    />
                                </div>
                            </div>
                        );
                    })}
                </Card>
            </div>
            <aside className={ui.formSide}>
                <Card className="space-y-2 border-violet-100 bg-violet-50/40">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="info" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.advanced.about_info', 'About shop information')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.about_info_hint', 'Use this page for troubleshooting and system checks when something looks wrong in the shop.')}
                            </p>
                        </div>
                    </div>
                </Card>
            </aside>
        </div>
    );
}

function MaintenanceTab({ maintenance }) {
    const form = useForm({
        enabled: !!maintenance.enabled,
        message: maintenance.message || '',
        header: maintenance.header || '',
        footer: maintenance.footer || '',
        banner: maintenance.banner || '',
        ips: maintenance.ips || '',
    });

    const addMyIp = () => {
        const ip = String(maintenance.client_ip || '').trim();
        if (!ip) {
            return;
        }
        const parts = String(form.data.ips || '')
            .split(',')
            .map((part) => part.trim())
            .filter(Boolean);
        if (!parts.includes(ip)) {
            parts.push(ip);
        }
        form.setData('ips', parts.join(', '));
    };

    const save = (e) => {
        e.preventDefault();
        form.put('/admin/advanced/maintenance', { preserveScroll: true });
    };

    const preview = () => {
        const formEl = document.createElement('form');
        formEl.method = 'POST';
        formEl.action = adminUrl('/admin/advanced/maintenance/preview');
        formEl.target = '_blank';
        const fields = {
            _token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            enabled: form.data.enabled ? '1' : '0',
            message: form.data.message || '',
            header: form.data.header || '',
            footer: form.data.footer || '',
            banner: form.data.banner || '',
            ips: form.data.ips || '',
        };
        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            formEl.appendChild(input);
        });
        document.body.appendChild(formEl);
        formEl.submit();
        formEl.remove();
    };

    return (
        <div className={ui.formLayout}>
            <div className={ui.formMain}>
                <Card
                    as="form"
                    className="space-y-4"
                    onSubmit={save}
                >
                    <SettingsSectionHead
                        icon="settings"
                        title={t('admin.advanced.maintenance', 'Maintenance mode')}
                        hint={t('admin.advanced.maintenance_page_hint', 'Close the storefront while staff keep using admin.')}
                    />
                    <FormErrors errors={form.errors} />
                    <FeatureTile
                        icon="lock"
                        tone="bg-rose-100 text-rose-600"
                        title={t('admin.advanced.maintenance', 'Maintenance mode')}
                        hint={t('admin.advanced.maintenance_hint', 'Closes the storefront. Admin stays available. Allowed IPs still see the shop.')}
                        value={form.data.enabled}
                        onChange={(on) => form.setData('enabled', on)}
                    />
                    <Field
                        label={t('admin.advanced.maintenance_header', 'Top header')}
                        hint={t('admin.advanced.maintenance_header_hint', 'Full-width bar at the top of the maintenance page. Clear the editor to hide it.')}
                        error={form.errors.header}
                    >
                        <Editor
                            value={form.data.header}
                            onChange={(html) => form.setData('header', html)}
                            placeholder={t('admin.advanced.maintenance_header_placeholder', 'Short status line for the top bar…')}
                        />
                    </Field>
                    <Field
                        label={t('admin.advanced.maintenance_message', 'Main message')}
                        hint={t('admin.advanced.maintenance_message_hint', 'Center card content shoppers see while the shop is closed.')}
                        error={form.errors.message}
                    >
                        <Editor
                            value={form.data.message}
                            onChange={(html) => form.setData('message', html)}
                            placeholder={t('admin.advanced.maintenance_editor_placeholder', 'Write the maintenance page content shoppers will see…')}
                        />
                    </Field>
                    <Field
                        label={t('admin.advanced.maintenance_footer', 'Card footer')}
                        hint={t('admin.advanced.maintenance_footer_hint', 'Small note under the main message inside the card. Clear to hide.')}
                        error={form.errors.footer}
                    >
                        <Editor
                            value={form.data.footer}
                            onChange={(html) => form.setData('footer', html)}
                            placeholder={t('admin.advanced.maintenance_footer_placeholder', 'Footer note under the main message…')}
                        />
                    </Field>
                    <Field
                        label={t('admin.advanced.maintenance_banner', 'Ground banner')}
                        hint={t('admin.advanced.maintenance_banner_hint', 'Sticky full-width bar fixed to the bottom of the screen. Clear to hide.')}
                        error={form.errors.banner}
                    >
                        <Editor
                            value={form.data.banner}
                            onChange={(html) => form.setData('banner', html)}
                            placeholder={t('admin.advanced.maintenance_banner_placeholder', 'Bottom banner for reopen timing or support…')}
                        />
                    </Field>
                    <Field
                        label={t('admin.advanced.allowed_ips', 'Allowlisted IPs')}
                        hint={t('admin.advanced.allowed_ips_hint', 'These addresses can still access the storefront.')}
                        error={form.errors.ips}
                    >
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <Input
                                wide
                                className="min-w-0 flex-1"
                                value={form.data.ips}
                                placeholder={t('admin.advanced.allowed_ips_placeholder', 'Comma separated IPs (e.g. 192.168.1.1, 10.0.0.1)')}
                                onChange={(e) => form.setData('ips', e.target.value)}
                            />
                            <Button type="button" variant="secondary" icon="monitor" onClick={addMyIp}>
                                {t('admin.advanced.add_my_ip', 'Add my IP')}
                            </Button>
                        </div>
                    </Field>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" icon="check" busy={form.processing}>
                            {t('admin.common.save', 'Save')}
                        </Button>
                        <Button type="button" variant="secondary" icon="eye" onClick={preview}>
                            {t('admin.advanced.preview', 'Preview')}
                        </Button>
                    </div>
                </Card>
            </div>
            <aside className={ui.formSide}>
                <Card className="space-y-2 border-violet-100 bg-violet-50/40">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="info" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.advanced.about_maintenance', 'About maintenance mode')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.about_maintenance_hint', 'Turn this on during updates or security work. Shoppers see your designed message while staff can still use admin.')}
                            </p>
                        </div>
                    </div>
                </Card>
                <Card className="space-y-3">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="layers" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.advanced.page_slots', 'Page slots')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.page_slots_hint', 'Header sits on top, main message in the card, footer under that message, and the ground banner sticks to the bottom of the screen.')}
                            </p>
                        </div>
                    </div>
                </Card>
                <Card className="space-y-3">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="lightbulb" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.advanced.tip', 'Tip')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.tip_add_ip', 'Add your current IP so you can still browse the storefront while maintenance is on.')}
                            </p>
                        </div>
                    </div>
                </Card>
            </aside>
        </div>
    );
}

function DemoTab({ demo, millions }) {
    const running = Boolean(millions?.running) || millions?.status === 'running';
    useEffect(() => {
        if (!running) {
            return undefined;
        }
        const timer = window.setInterval(() => {
            router.reload({ only: ['demo', 'millions'] });
        }, 4000);
        return () => window.clearInterval(timer);
    }, [running]);

    const install = () => {
        if (!window.confirm(t('admin.advanced.demo_confirm', 'Install demo products, customers, orders, and discount rules? Existing rows are kept; missing demo rows are added.'))) {
            return;
        }
        router.post('/admin/advanced/demo/install');
    };

    const remove = () => {
        if (!window.confirm(t('admin.advanced.demo_remove_confirm', 'Remove sample products, customers, orders, discounts, and demo page copy? Your admin account and shop configuration stay. You can install demo data again afterwards.'))) {
            return;
        }
        router.post('/admin/advanced/demo/remove');
    };

    const installMillions = () => {
        if (!window.confirm(t('admin.advanced.millions_confirm', 'Load about one million products, customers, orders, reviews, and wishlists? This runs in the background and can take a long time. Do not use this on a live shop.'))) {
            return;
        }
        router.post('/admin/advanced/demo/millions');
    };

    const included = [
        t('admin.advanced.demo_include_products', 'Sample products (with categories and images)'),
        t('admin.advanced.demo_include_customers', 'Sample customers'),
        t('admin.advanced.demo_include_orders', 'Sample orders (completed and pending)'),
        t('admin.advanced.demo_include_cms', 'Pages and CMS content'),
        t('admin.advanced.demo_include_settings', 'Store settings (basic configuration)'),
    ];

    const millionIncluded = [
        t('admin.advanced.millions_include_products', '1,000,000 products (SCALE- SKUs, no extra images)'),
        t('admin.advanced.millions_include_customers', '1,000,000 customers and addresses'),
        t('admin.advanced.millions_include_orders', '1,000,000 orders and line items'),
        t('admin.advanced.millions_include_more', '1,000,000 reviews and wishlist rows'),
    ];

    const progress = millions?.progress || {};
    const progressLabel = progress.phase && progress.phase !== 'idle' && progress.phase !== 'done'
        ? `${progress.phase}: ${Number(progress.done || 0).toLocaleString()} / ${Number(progress.target || 0).toLocaleString()}`
        : null;

    return (
        <div className={ui.formLayout}>
            <div className={ui.formMain}>
                <Card className="space-y-4">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="layers" className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.demo', 'Demo data')}</h2>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.demo_lead', 'Install sample data to explore the platform with products, customers, orders, and more.')}
                            </p>
                        </div>
                    </div>

                    <Alert type="info" className="mb-0">
                        {t('admin.advanced.demo_test_only', 'Demo data is for testing and development only. Do not install it on a production store.')}
                    </Alert>

                    <div>
                        <h3 className={cx(ui.sectionTitle, 'mb-2 text-base')}>
                            {t('admin.advanced.demo_whats_included', 'What is included')}
                        </h3>
                        <ul className="space-y-2">
                            {included.map((item) => (
                                <li key={item} className="flex items-start gap-2 text-sm text-neutral-700">
                                    <span className={ui.tipCheck}>
                                        <Icon name="check" className="h-3 w-3" />
                                    </span>
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {demo?.installed ? (
                        <Alert type="info" className="mb-0">
                            {t('admin.advanced.demo_already', 'Demo data is installed. Remove it to go back to an empty catalog, or leave it to keep exploring the sample shop.')}
                        </Alert>
                    ) : null}

                    <div className="flex flex-wrap gap-2">
                        {demo?.installed ? (
                            <Button variant="danger" icon="trash" onClick={remove}>
                                {t('admin.advanced.demo_remove', 'Remove demo data')}
                            </Button>
                        ) : (
                            <Button icon="download" onClick={install}>
                                {t('admin.advanced.demo_install', 'Install demo data')}
                            </Button>
                        )}
                        <Button variant="secondary" icon="database" onClick={installMillions} busy={running} disabled={running}>
                            {t('admin.advanced.millions_install', 'Test millions data')}
                        </Button>
                    </div>
                </Card>

                <Card className="space-y-4">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="database" className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.millions', 'Test millions data')}</h2>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.millions_lead', 'Fill the shop with about one million products, customers, and orders so admin lists can be checked at real-store size. Runs in the background — this page is not the installer demo pack.')}
                            </p>
                        </div>
                    </div>
                    <Alert type="warning" className="mb-0">
                        {t('admin.advanced.millions_warning', 'Needs a lot of disk and time. Existing SCALE- rows are kept; missing rows are added. Do not run this on production.')}
                    </Alert>
                    <ul className="space-y-2">
                        {millionIncluded.map((item) => (
                            <li key={item} className="flex items-start gap-2 text-sm text-neutral-700">
                                <span className={ui.tipCheck}>
                                    <Icon name="check" className="h-3 w-3" />
                                </span>
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                    {running ? <Badge tone="warning">{t('admin.advanced.millions_running', 'Loading millions of rows…')}</Badge> : null}
                    {millions?.installed && !running ? <Badge tone="success">{t('admin.advanced.millions_installed', 'Millions dataset installed')}</Badge> : null}
                    {progressLabel ? <p className={ui.muted}>{progressLabel}</p> : null}
                    {millions?.error ? <Alert type="error" className="mb-0">{millions.error}</Alert> : null}
                    <Button icon="database" onClick={installMillions} busy={running} disabled={running}>
                        {running
                            ? t('admin.advanced.millions_in_progress', 'Loading…')
                            : t('admin.advanced.millions_install', 'Test millions data')}
                    </Button>
                    <p className={ui.muted}>
                        {t('admin.advanced.millions_cli', 'CLI: php artisan wento:million-store --force')}
                    </p>
                </Card>
            </div>

            <aside className={ui.formSide}>
                <Card className="space-y-3">
                    <h3 className={cx(ui.sectionTitle, 'mb-0 text-base')}>
                        {t('admin.advanced.current_data', 'Current data')}
                    </h3>
                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-neutral-100 bg-neutral-50 px-3 py-2.5">
                            <span className="text-sm text-neutral-600">{t('admin.catalog.products', 'Products')}</span>
                            <span className="text-sm font-semibold tabular-nums">{demo?.products ?? 0}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-neutral-100 bg-neutral-50 px-3 py-2.5">
                            <span className="text-sm text-neutral-600">{t('admin.nav.customers', 'Customers')}</span>
                            <span className="text-sm font-semibold tabular-nums">{demo?.customers ?? 0}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-neutral-100 bg-neutral-50 px-3 py-2.5">
                            <span className="text-sm text-neutral-600">{t('admin.nav.orders', 'Orders')}</span>
                            <span className="text-sm font-semibold tabular-nums">{demo?.orders ?? 0}</span>
                        </div>
                    </div>
                </Card>
                {demo?.installed ? null : (
                    <Alert type="warning" className="mb-0">
                        <strong className="block font-semibold">{t('admin.advanced.important', 'Important')}</strong>
                        {t('admin.advanced.demo_backup_hint', 'Installing demo data may overwrite existing data. We recommend creating a backup before proceeding.')}
                    </Alert>
                )}
            </aside>
        </div>
    );
}

export default function Advanced({
    tab,
    profiler,
    queries,
    tables,
    saved_queries: savedQueries,
    query_result: queryResult,
    draft_sql: draftSql,
    file_logs: fileLogs,
    file_log_filters: fileLogFilters,
    info,
    maintenance,
    demo,
    millions,
}) {
    const current = tab || 'profiler';
    const { tools } = usePage().props;

    return (
        <Layout>
            <PageHeader
                title={t('admin.advanced.title', 'Advanced')}
                description={t('admin.advanced.lead', 'Manage system settings, logs, and advanced tools for your store.')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.nav.configure', 'Configure') },
                    { label: t('admin.nav.advanced', 'Advanced') },
                ]}
                actions={tools?.maintenance ? (
                    <Badge tone="warning">{t('admin.advanced.shop_closed', 'Shop closed')}</Badge>
                ) : null}
            />
            <AdvancedClubTabs current={current} queryCount={savedQueries?.length} />
            {current === 'queries' ? <QueriesTab tables={tables} saved={savedQueries} result={queryResult} draft_sql={draftSql} /> : null}
            {current === 'files' ? <FileLogTab rows={fileLogs} filters={fileLogFilters} /> : null}
            {current === 'info' ? <InfoTab info={info} /> : null}
            {current === 'maintenance' ? <MaintenanceTab maintenance={maintenance} /> : null}
            {current === 'demo' ? <DemoTab demo={demo} millions={millions} /> : null}
            {current === 'profiler' ? <ProfilerTab enabled={profiler} queries={queries} /> : null}
        </Layout>
    );
}
