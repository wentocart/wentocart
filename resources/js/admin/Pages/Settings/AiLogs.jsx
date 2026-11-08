import { Link, router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    Badge,
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
    Table,
    THead,
    Th,
    Tr,
    Td,
    useListSelection,
    ui,
    t,
} from '../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

export default function AiLogs({ chats, filters = {}, count = 0 }) {
    const rows = chats?.data || (Array.isArray(chats) ? chats : []);
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        q: filters.q || '',
        title: filters.title || '',
        admin: filters.admin || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        date: filters.date || '',
        sort: filters.sort || 'updated_at',
        dir: filters.dir === 'asc' ? 'asc' : 'desc',
        page_size: filters.page_size || '',
    }), [filters]);

    const selection = useListSelection(rows);

    const apply = useCallback((next) => {
        router.get('/admin/ai/logs', cleanQuery({ ...query, ...next }), {
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
        const dir = query.sort === column && query.dir === 'desc' ? 'asc' : 'desc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

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
    }, [bulkBusy, selection]);

    const exportSelected = useCallback(() => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (ids.length) {
            window.location.href = adminUrl(`/admin/ai/logs/export?ids=${encodeURIComponent(ids.join(','))}`);
        } else {
            window.location.href = adminUrl('/admin/ai/logs/export');
        }
    }, [selection.selectedIds]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.ai.logs_title', 'Admin chats')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/ai', label: t('admin.nav.ai', 'AI Assistant') },
                    { label: t('admin.nav.ai_admin_chats', 'Admin chats') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" native href="/admin/ai">
                            {t('admin.ai.back_settings', 'AI settings')}
                        </Button>
                        <Button
                            variant="secondary"
                            icon="download"
                            onClick={() => { window.location.href = adminUrl('/admin/ai/logs/export'); }}
                        >
                            {t('admin.common.export', 'Export')}
                        </Button>
                    </div>
                )}
            />
            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'delete',
                                label: t('admin.common.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.ai.delete_selected_chats', 'Remove selected chat sessions?'),
                                onClick: () => runBulk('/admin/ai/logs/bulk-delete'),
                            },
                            {
                                key: 'export',
                                label: t('admin.common.export_selected', 'Export selected'),
                                icon: 'download',
                                onClick: exportSelected,
                            },
                        ]}
                    />
                )}
                footer={chats?.links ? <ListFooter pagination={chats} query={query} /> : null}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.ai.log_id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="updated_at" label={t('admin.ai.chat_when', 'Updated')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="admin_id" label={t('admin.ai.log_admin', 'Admin')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="title" label={t('admin.ai.chat_title', 'Title')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.ai.chat_messages', 'Messages')}</Th>
                            <Th>{t('admin.ai.log_preview', 'Preview')}</Th>
                            <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
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
                                <ColumnFilter
                                    name="date"
                                    type="date"
                                    value={query.date}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="admin"
                                    value={query.admin}
                                    placeholder={t('admin.ai.filter_admin', 'Filter admin…')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="title"
                                    value={query.title}
                                    placeholder={t('admin.ai.search_admin_chats', 'Search title…')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/ai/logs" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>
                                {t('admin.ai.no_admin_chats', 'No admin chat sessions yet.')}
                            </EmptyState>
                        ) : (
                            rows.map((row) => (
                                <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(row.id)}
                                            onChange={() => selection.toggle(row.id)}
                                            ariaLabel={row.title}
                                        />
                                    </Td>
                                    <Td col="id">{row.id}</Td>
                                    <Td className="whitespace-nowrap text-xs text-neutral-600">{row.updated_at}</Td>
                                    <Td>
                                        <div className="font-medium text-neutral-900">{row.admin_name}</div>
                                        <div className={ui.muted}>{row.admin_email}</div>
                                    </Td>
                                    <Td>
                                        <Link href={`/admin/ai/logs/${row.id}`} className="font-medium text-neutral-900 hover:text-sky-600 hover:underline">
                                            {row.title}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <Badge tone="neutral">{row.message_count}</Badge>
                                    </Td>
                                    <Td className="max-w-xs truncate text-xs text-neutral-600">{row.preview}</Td>
                                    <Td actions>
                                        <RowActions
                                            viewHref={`/admin/ai/logs/${row.id}`}
                                            canEdit={false}
                                            onDelete={() => router.delete(`/admin/ai/logs/${row.id}`)}
                                            confirmMessage={t('admin.ai.delete_chat', 'Delete this chat?')}
                                        />
                                    </Td>
                                </Tr>
                            ))
                        )}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
