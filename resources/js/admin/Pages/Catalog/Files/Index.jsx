import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../../Layout';
import {
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    KpiCards,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Icon,
    Tooltip,
    useListSelection,
    ui,
    cx,
    t,
} from '../../../ui';
import { catalogImageUrl } from '../../../lib/catalogMedia';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null) {
            delete next[key];
        }
    });
    return next;
}

function fileExtension(name) {
    const part = String(name || '').split('.').pop();
    return part ? part.toUpperCase() : '—';
}

function FileDownloadButton({ href, label }) {
    if (!href) {
        return null;
    }

    return (
        <Tooltip content={label} placement="top" portal>
            <a
                href={href}
                className={cx(
                    ui.rowActionsBtn,
                    'inline-flex h-8 w-8 shrink-0 items-center justify-center text-neutral-600 hover:bg-neutral-100 hover:text-violet-700',
                )}
                aria-label={label}
                download
            >
                <Icon name="download" className="h-4 w-4" />
            </a>
        </Tooltip>
    );
}

function FileListThumb({ row }) {
    if (row.preview_url) {
        return (
            <img
                src={catalogImageUrl(row.preview_url, 'sm')}
                alt=""
                className="h-12 w-12 shrink-0 rounded-lg border border-neutral-200 object-cover bg-neutral-100"
            />
        );
    }

    return (
        <div
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-100"
            aria-hidden
        >
            <span className="text-[10px] font-semibold uppercase leading-none text-neutral-500">
                {fileExtension(row.original_filename)}
            </span>
        </div>
    );
}

export default function Index({ files, counts = {}, filters = {}, can = {} }) {
    const rows = files.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        q: filters.q || '',
        name: filters.name || '',
        file: filters.file || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        products_min: filters.products_min || '',
        products_max: filters.products_max || '',
        size_min: filters.size_min || '',
        size_max: filters.size_max || '',
        created: filters.created || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/files', cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false && can.delete !== false,
    });

    const runBulk = useCallback((url, payload = {}) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post(url, { ...payload, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    }, [bulkBusy, selection.clear, selection.selectedIds]);

    const all = Number(counts.all ?? 0);
    const linked = Number(counts.linked ?? 0);
    const unused = Number(counts.unused ?? 0);
    const linkedRate = all > 0 ? Math.round((linked / all) * 1000) / 10 : 0;
    const kpis = [
        { key: 'total', label: t('admin.files.all_files', 'All files'), value: all.toLocaleString(), trend: 0 },
        { key: 'linked', label: t('admin.files.in_use', 'In use'), value: linked.toLocaleString(), trend: 0 },
        { key: 'unused', label: t('admin.files.unused', 'Unused'), value: unused.toLocaleString(), trend: 0 },
        { key: 'rate', label: t('admin.files.linked_rate', 'Linked rate'), value: `${linkedRate}%`, trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.files.title', 'Files')}
                total={files.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.files.title', 'Files') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        {can.create !== false ? (
                            <Button icon="plus" href="/admin/files/create">
                                {t('admin.files.add', 'Add new file')}
                            </Button>
                        ) : null}
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                bulk={can.delete !== false ? (
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[{
                            key: 'delete',
                            label: t('admin.products.delete_selection', 'Delete selection'),
                            icon: 'trash',
                            tone: 'danger',
                            confirm: t('admin.files.delete_selected', 'Delete selected files? Product links will be removed.'),
                            onClick: () => runBulk('/admin/files/bulk-delete'),
                        }]}
                    />
                ) : null}
                footer={<ListFooter pagination={files} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            {can.delete !== false ? (
                                <Th check>
                                    <SelectionCheckbox
                                        checked={selection.allSelected}
                                        indeterminate={selection.someSelected}
                                        onChange={selection.toggleAll}
                                        ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                    />
                                </Th>
                            ) : null}
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.files.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="original_filename" label={t('admin.files.file', 'File')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="file_size" label={t('admin.files.size', 'Size')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="products_count" label={t('admin.files.products', 'Products')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.files.created', 'Created')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            {can.delete !== false ? <Th check className={ui.thFilter} /> : null}
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter
                                    minName="id_min"
                                    maxName="id_max"
                                    minValue={query.id_min}
                                    maxValue={query.id_max}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.files.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="file" value={query.file} placeholder={t('admin.files.search_file', 'Search file')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="size_min"
                                    maxName="size_max"
                                    minValue={query.size_min}
                                    maxValue={query.size_max}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="products_min"
                                    maxName="products_max"
                                    minValue={query.products_min}
                                    maxValue={query.products_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="created" value={query.created} type="date" placeholder={t('admin.files.created', 'Created')} onApply={applyColumn} />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/files" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={can.delete !== false ? 8 : 7}>
                                {t('admin.files.empty', 'No files have been uploaded yet.')}
                            </EmptyState>
                        ) : rows.map((row) => {
                            const canDelete = row.actions?.delete !== false && can.delete !== false;

                            return (
                                <Tr key={row.id}>
                                    {can.delete !== false ? (
                                        <Td check>
                                            {canDelete ? (
                                                <SelectionCheckbox
                                                    checked={selection.isSelected(row.id)}
                                                    onChange={() => selection.toggle(row.id)}
                                                    ariaLabel={`${t('admin.common.select', 'Select')} ${row.name}`}
                                                />
                                            ) : null}
                                        </Td>
                                    ) : null}
                                    <Td col="id">{row.id}</Td>
                                    <Td>
                                        <div className={`${ui.row} min-w-0 items-center`}>
                                            <FileListThumb row={row} />
                                            <FileDownloadButton
                                                href={row.download_url}
                                                label={t('admin.files.download', 'Download file')}
                                            />
                                            <div className={`${ui.cellStack} min-w-0`}>
                                                <div className={`${ui.cellStrong} truncate`}>{row.name}</div>
                                                <div className={`${ui.cellSub} truncate`}>
                                                    {row.file_type_label || row.mime_type || '—'}
                                                </div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>{row.original_filename || '—'}</Td>
                                    <Td>{row.file_size_label}</Td>
                                    <Td col="center">{row.products_count ?? 0}</Td>
                                    <Td>{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={row.actions?.edit ? `/admin/files/${row.id}/edit` : null}
                                            canEdit={row.actions?.edit !== false}
                                            canDelete={canDelete}
                                            confirmMessage={t('admin.files.delete_confirm', 'Delete this file? Product links will be removed.')}
                                            onDelete={() => {
                                                router.delete(`/admin/files/${row.id}`, { preserveScroll: true });
                                            }}
                                        />
                                    </Td>
                                </Tr>
                            );
                        })}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
