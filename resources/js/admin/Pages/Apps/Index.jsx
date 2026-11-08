import { useCallback, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import Layout from '../Layout';
import {
    BulkActions,
    Button,
    FormErrors,
    Icon,
    ListPanel,
    PageHeader,
    Select,
    useListSelection,
    ui,
    cx,
    t,
} from '../../ui';
import {
    buildModuleRows,
    categoryOptions,
    filterModules,
    groupByCategory,
} from './extensionManager';
import { ModuleGroup, UploadModuleModal } from './ModuleRow';
import { ExtensionGridGroup } from './ExtensionCard';
import { ExtensionBusyProvider, EXTENSION_RELOAD_PROPS } from './extensionBusy';

const VIEW_STORAGE_KEY = 'admin.extensions.view';

function readStoredView() {
    if (typeof window === 'undefined') {
        return 'grid';
    }
    const stored = window.localStorage.getItem(VIEW_STORAGE_KEY);
    return stored === 'list' ? 'list' : 'grid';
}

const EXTENSION_FILTER_WIDTH = {
    category: 'w-[11rem]',
    status: 'w-[12.5rem]',
    bulk: 'w-[11rem]',
};

function ViewToggle({ value, onChange }) {
    return (
        <div className="inline-flex h-[42px] shrink-0 items-center rounded-xl border border-neutral-200 bg-white p-1 shadow-sm" role="group" aria-label={t('admin.apps.view_mode', 'View mode')}>
            <button
                type="button"
                className={cx(
                    'inline-flex h-[34px] w-[34px] items-center justify-center rounded-lg transition',
                    value === 'grid' ? 'bg-violet-50 text-violet-700' : 'text-neutral-500 hover:bg-neutral-50 hover:text-neutral-800',
                )}
                aria-pressed={value === 'grid'}
                aria-label={t('admin.apps.grid_view', 'Grid view')}
                onClick={() => onChange('grid')}
            >
                <Icon name="grid" className="h-4 w-4" />
            </button>
            <button
                type="button"
                className={cx(
                    'inline-flex h-[34px] w-[34px] items-center justify-center rounded-lg transition',
                    value === 'list' ? 'bg-violet-50 text-violet-700' : 'text-neutral-500 hover:bg-neutral-50 hover:text-neutral-800',
                )}
                aria-pressed={value === 'list'}
                aria-label={t('admin.apps.list_view', 'List view')}
                onClick={() => onChange('list')}
            >
                <Icon name="list" className="h-4 w-4" />
            </button>
        </div>
    );
}

export default function Index({ apps = [], catalog = [], errors = {} }) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [status, setStatus] = useState('all');
    const [uploadOpen, setUploadOpen] = useState(false);
    const [view, setView] = useState(readStoredView);
    const [bulkBusy, setBulkBusy] = useState(false);

    const setViewMode = useCallback((next) => {
        setView(next);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(VIEW_STORAGE_KEY, next);
        }
    }, []);

    const allRows = useMemo(() => buildModuleRows(apps, catalog), [apps, catalog]);
    const filteredRows = useMemo(
        () => filterModules(allRows, { tab: 'modules', q: search, category, status }),
        [allRows, search, category, status],
    );
    const groupedRows = useMemo(() => groupByCategory(filteredRows), [filteredRows]);
    const categories = useMemo(() => categoryOptions(allRows), [allRows]);

    const selection = useListSelection(
        filteredRows.filter((row) => row.installed),
        { idKey: 'key', canSelect: (row) => row.installed },
    );

    const runBulk = useCallback(async (enabled) => {
        if (!selection.selectedIds.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        await Promise.all(selection.selectedIds.map((id) => new Promise((resolve) => {
            const [vendor, name] = String(id).split('/');
            if (!vendor || !name) {
                resolve();
                return;
            }
            router.post(`/admin/extensions/${vendor}/${name}/enable`, { enabled }, {
                preserveScroll: true,
                preserveState: true,
                async: true,
                only: EXTENSION_RELOAD_PROPS,
                onFinish: resolve,
            });
        })));
        setBulkBusy(false);
        selection.clear();
    }, [bulkBusy, selection]);

    const bulkItems = useMemo(() => [
        {
            key: 'enable',
            label: t('admin.apps.enable_selected', 'Enable selected'),
            onClick: () => runBulk(true),
        },
        {
            key: 'disable',
            label: t('admin.apps.disable_selected', 'Disable selected'),
            onClick: () => runBulk(false),
        },
    ], [runBulk]);

    const emptyMessage = search || category !== 'all' || status !== 'all'
        ? t('admin.apps.empty_search', 'No extensions found for this search or filter.')
        : t('admin.apps.empty', 'No extensions installed yet.');

    return (
        <Layout>
            <FormErrors errors={errors} variant="growl" />
            <ExtensionBusyProvider>
                <PageHeader
                    title={t('admin.apps.manager_title', 'Extension Manager')}
                    description={t('admin.apps.manager_subtitle', 'Manage and extend your store with powerful extensions.')}
                    crumbs={[
                        { label: t('admin.apps.apps', 'Extensions') },
                    ]}
                    actions={(
                        <Button type="button" variant="primary" icon="upload" onClick={() => setUploadOpen(true)}>
                            {t('admin.apps.install_extension', 'Install Extension')}
                        </Button>
                    )}
                />

                <div className="space-y-4">
                    <ListPanel
                        filters={(
                            <div className={cx(ui.filterBar, 'gap-2')}>
                                <label className={cx(ui.filterSearch, 'min-w-[220px] flex-1')}>
                                    <span className={ui.filterSearchIcon}>
                                        <Icon name="search" />
                                    </span>
                                    <input
                                        type="search"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder={t('admin.apps.search_modules', 'Search extensions…')}
                                        className={ui.filterSearchInput}
                                        aria-label={t('admin.apps.search_modules', 'Search extensions…')}
                                    />
                                </label>
                                <Select
                                    filter
                                    value={category}
                                    onChange={(e) => setCategory(e.target.value)}
                                    aria-label={t('admin.apps.filter_category', 'Category')}
                                    className={EXTENSION_FILTER_WIDTH.category}
                                >
                                    <option value="all">{t('admin.apps.all_categories', 'All categories')}</option>
                                    {categories.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </Select>
                                <Select
                                    filter
                                    value={status}
                                    onChange={(e) => setStatus(e.target.value)}
                                    aria-label={t('admin.apps.filter_status', 'Status')}
                                    className={EXTENSION_FILTER_WIDTH.status}
                                >
                                    <option value="all">{t('admin.apps.all_statuses', 'Show all extensions')}</option>
                                    <option value="installed">{t('admin.apps.status_installed', 'Installed')}</option>
                                    <option value="not_installed">{t('admin.apps.status_available', 'Available')}</option>
                                    <option value="enabled">{t('admin.apps.status_enabled', 'Enabled')}</option>
                                    <option value="disabled">{t('admin.apps.status_disabled', 'Disabled')}</option>
                                    <option value="alert">{t('admin.apps.status_alert', 'Needs attention')}</option>
                                </Select>
                                <BulkActions
                                    count={selection.selectedCount}
                                    busy={bulkBusy}
                                    barClassName="border-b-0 p-0"
                                    buttonClassName={cx(ui.filterBulkBtn, EXTENSION_FILTER_WIDTH.bulk)}
                                    items={bulkItems}
                                />
                                <ViewToggle value={view} onChange={setViewMode} />
                            </div>
                        )}
                        table={false}
                    >
                        {filteredRows.length === 0 ? (
                            <div className={ui.empty}>
                                <Icon name="package" className="mx-auto mb-3 h-8 w-8 text-neutral-300" />
                                {emptyMessage}
                            </div>
                        ) : view === 'grid' ? (
                            <div className="space-y-3 p-3">
                                {groupedRows.map((group) => (
                                    <ExtensionGridGroup
                                        key={group.key}
                                        groupKey={group.key}
                                        label={group.label}
                                        count={group.rows.length}
                                        rows={group.rows}
                                        selection={selection}
                                    />
                                ))}
                            </div>
                        ) : (
                            <div className="space-y-3 p-3">
                                {groupedRows.map((group) => (
                                    <ModuleGroup
                                        key={group.key}
                                        groupKey={group.key}
                                        label={group.label}
                                        count={group.rows.length}
                                        rows={group.rows}
                                        selection={selection}
                                    />
                                ))}
                            </div>
                        )}
                    </ListPanel>
                </div>

                <UploadModuleModal open={uploadOpen} onClose={() => setUploadOpen(false)} />
            </ExtensionBusyProvider>
        </Layout>
    );
}
