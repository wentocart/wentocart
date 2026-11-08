import { router, useForm } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../../Layout';
import { adminUrl } from '../../../webPath';
import {
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    Field,
    FilterReset,
    Input,
    ListFooter,
    ListPanel,
    ListSettings,
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
    useListSelection,
    ui,
    t,
} from '../../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

export default function Index({ subscribers, filters = {}, count = 0, campaigns = [] }) {
    const rows = subscribers.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        q: filters.q || '',
        email: filters.email || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        date: filters.date || '',
        sort: filters.sort || 'id',
        dir: filters.dir === 'asc' ? 'asc' : 'desc',
    }), [filters]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const campaign = useForm({ subject: '', html_body: '' });

    const apply = useCallback((next) => {
        router.get('/admin/newsletter', cleanQuery({ ...query, ...next }), {
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
    }, [bulkBusy, selection.clear, selection.selectedIds]);

    const exportSelected = useCallback(() => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (ids.length) {
            window.location.href = adminUrl(`/admin/newsletter/export?ids=${encodeURIComponent(ids.join(','))}`);
        }
    }, [selection.selectedIds]);

    const kpis = [
        {
            key: 'total',
            label: t('admin.newsletter.subscribers', 'Subscribers'),
            value: Number(count ?? 0).toLocaleString(),
            trend: 0,
        },
        {
            key: 'campaigns',
            label: t('admin.newsletter.campaigns', 'Campaigns'),
            value: Number(campaigns.length ?? 0).toLocaleString(),
            trend: 0,
        },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.newsletter.title', 'Newsletter')}
                total={subscribers.total ?? count}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.newsletter.title', 'Newsletter') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <ListSettings
                            listUrl="/admin/newsletter"
                            importUrl="/admin/newsletter/import"
                            importSampleUrl="/admin/newsletter/import/sample"
                            exportUrl="/admin/newsletter/export"
                            query={query}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <section className={`${ui.sectionCard} mb-6`}>
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{t('admin.newsletter.campaign', 'Send campaign')}</h2>
                </div>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        campaign.post('/admin/newsletter/campaigns', {
                            preserveScroll: true,
                            onSuccess: () => campaign.reset(),
                        });
                    }}
                >
                    <Field label={t('admin.newsletter.subject', 'Subject')} error={campaign.errors.subject}>
                        <Input
                            wide
                            value={campaign.data.subject}
                            onChange={(e) => campaign.setData('subject', e.target.value)}
                            placeholder={t('admin.newsletter.subject_placeholder', 'e.g. Monthly updates and exclusive offers')}
                        />
                    </Field>
                    <Field label={t('admin.newsletter.body', 'HTML body')} error={campaign.errors.html_body}>
                        <textarea
                            className={ui.textarea}
                            rows={5}
                            value={campaign.data.html_body}
                            onChange={(e) => campaign.setData('html_body', e.target.value)}
                            placeholder="<p>Hello subscribers...</p>"
                        />
                    </Field>
                    <div className="flex items-center justify-between">
                        <Button type="submit" busy={campaign.processing}>
                            {t('admin.newsletter.send', 'Send to subscribers')}
                        </Button>
                        {campaigns.length ? (
                            <p className={ui.muted}>
                                {t('admin.newsletter.last_campaign', 'Last')}: {campaigns[0].subject} ({campaigns[0].sent_count})
                            </p>
                        ) : null}
                    </div>
                </form>
            </section>

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
                                confirm: t('admin.newsletter.delete_selected', 'Remove selected subscribers?'),
                                onClick: () => runBulk('/admin/newsletter/bulk-delete'),
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
                footer={<ListFooter pagination={subscribers} query={query} />}
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
                            <SortHeader column="email" label={t('admin.common.email', 'Email')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.newsletter.subscribed_at', 'Subscribed')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                    name="email"
                                    value={query.email}
                                    placeholder={t('admin.newsletter.search_email', 'Search email…')}
                                    onApply={applyColumn}
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
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/newsletter" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={5}>
                                {t('admin.newsletter.empty', 'No subscribers yet. When a customer submits the newsletter form, they appear here.')}
                            </EmptyState>
                        ) : (
                            rows.map((row) => (
                                <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(row.id)}
                                            onChange={() => selection.toggle(row.id)}
                                            ariaLabel={row.email}
                                        />
                                    </Td>
                                    <Td col="id">{row.id}</Td>
                                    <Td className="font-medium text-neutral-900">{row.email}</Td>
                                    <Td>{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</Td>
                                    <Td actions>
                                        <RowActions
                                            canView={false}
                                            canEdit={false}
                                            onDelete={() => router.delete(`/admin/newsletter/${row.id}`)}
                                            confirmMessage={t('admin.newsletter.delete_one', 'Remove this subscriber?')}
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
