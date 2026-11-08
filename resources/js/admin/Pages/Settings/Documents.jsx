import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    KpiCards,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    catalogBulkActionItems,
    listStatusFilterOptions,
    statusCountKpis,
    ui,
    t,
    useListSelection,
} from '../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') delete next[key];
    });
    return next;
}

export default function Documents({ templates, types = [], counts = {}, filters = {} }) {
    const rows = templates?.data || [];
    const query = useMemo(() => ({
        status: filters.status || 'all',
        name: filters.name || '',
        code: filters.code || '',
        type: filters.type || 'all',
        subject: filters.subject || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir || 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/documents', cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.dir, query.sort]);

    const tabs = [
        { key: 'all', label: t('admin.common.all', 'All'), count: counts.all || 0 },
        { key: 'active', label: t('admin.common.active', 'Active'), count: counts.active || 0 },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), count: counts.inactive || 0 },
    ];
    const kpis = statusCountKpis(counts, {
        total: t('admin.documents.templates', 'Templates'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });
    const typeOptions = [
        { value: 'all', label: t('admin.common.all', 'All') },
        ...types,
    ];
    const selection = useListSelection(rows);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [busyId, setBusyId] = useState(null);

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

    const toggleRow = (row) => {
        if (busyId === row.id) {
            return;
        }
        setBusyId(row.id);
        router.post(`/admin/documents/${row.id}/toggle`, { is_enabled: row.is_enabled ? 0 : 1 }, {
            preserveScroll: true,
            onFinish: () => setBusyId(null),
        });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.documents.document_designs', 'Document designs')}
                description={t('admin.documents.lead', 'Invoice, credit slip, and delivery slip each use one template. The In use badge is the PDF that is downloaded and emailed.')}
                total={templates?.total || 0}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.documents.document_designs', 'Document designs') },
                ]}
                actions={(
                    <Button icon="plus" href="/admin/documents/create">
                        {t('admin.documents.add_template', 'Add template')}
                    </Button>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabs} value={query.status} onChange={(status) => apply({ status })} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/documents/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/documents/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/documents/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/documents/bulk-delete'),
                            deleteConfirm: t('admin.documents.delete_selected', 'Delete selected custom templates? Built-in templates will be skipped.'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={templates} query={query} />}
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
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.documents.template', 'Template')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.documents.code', 'Code')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="type" label={t('admin.documents.type', 'Type')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="subject" label={t('admin.documents.email_subject', 'Email subject')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="status" label={t('admin.common.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact />
                            </Th>
                            <Th className={ui.thFilter}><ColumnFilter name="name" value={query.name} placeholder={t('admin.documents.search_name', 'Search name')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="code" value={query.code} placeholder={t('admin.documents.search_code', 'Search code')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="type" value={query.type} type="select" options={typeOptions} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="subject" value={query.subject} placeholder={t('admin.documents.search_subject', 'Search subject')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="status" value={query.status} type="select" options={listStatusFilterOptions()} onApply={applyColumn} /></Th>
                            <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/documents" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.documents.no_templates', 'No document templates yet')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${row.name}`}
                                    />
                                </Td>
                                <Td className={ui.tdId}>{row.id}</Td>
                                <Td>
                                    <span className="inline-flex flex-wrap items-center gap-2">
                                        <span className={ui.cellStrong}>{row.name}</span>
                                        {row.is_in_use ? (
                                            <Badge tone="success">{t('admin.documents.in_use', 'In use')}</Badge>
                                        ) : null}
                                    </span>
                                </Td>
                                <Td><code className="text-xs">{row.code}</code></Td>
                                <Td><Badge tone={row.type === 'custom' ? 'neutral' : 'primary'}>{row.type_label}</Badge></Td>
                                <Td><span className="text-sm">{row.email_subject || '—'}</span></Td>
                                <Td col="status">
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        busy={busyId === row.id}
                                        onToggle={() => toggleRow(row)}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/documents/${row.id}/edit`}
                                        canDelete={!row.is_system}
                                        confirmMessage={t('admin.documents.delete_this_template', 'Delete this template?')}
                                        onDelete={() => router.delete(`/admin/documents/${row.id}`)}
                                        items={[
                                            row.type !== 'custom' && !row.is_in_use ? {
                                                key: 'use',
                                                icon: 'check',
                                                label: t('admin.documents.set_in_use', 'Set as in use'),
                                                onClick: () => router.post(`/admin/documents/${row.id}/use`, {}, { preserveScroll: true }),
                                            } : null,
                                            {
                                                key: 'duplicate',
                                                icon: 'copy',
                                                label: t('admin.documents.duplicate', 'Duplicate'),
                                                onClick: () => router.post(`/admin/documents/${row.id}/duplicate`),
                                            },
                                        ].filter(Boolean)}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
