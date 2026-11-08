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
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    Input,
    ui,
    t,
    useListSelection,
} from '../../ui';

function tabs() {
    return [
        { key: 'all', label: t('admin.questions.all', 'All') },
        { key: 'pending', label: t('admin.questions.pending', 'Pending') },
        { key: 'approved', label: t('admin.questions.approved', 'Approved') },
        { key: 'rejected', label: t('admin.questions.rejected', 'Rejected') },
    ];
}

const tone = { pending: 'warning', approved: 'success', rejected: 'danger' };

function moderate(id, payload) {
    router.post(`/admin/questions/${id}`, payload, { preserveScroll: true });
}

export default function Questions({ questions, filters = {} }) {
    const rows = questions.data || [];
    const query = useMemo(() => ({
        product: filters.product || '',
        customer: filters.customer || '',
        question: filters.question || '',
        status: filters.status || 'all',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);
    const [draft, setDraft] = useState({});
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/questions', payload, { preserveState: true, preserveScroll: true, replace: true });
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

    const runBulk = (action) => {
        const ids = selection.selectedIds;
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post('/admin/questions/bulk', { action, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.questions.questions', 'Questions')}
                total={questions.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customers', label: t('admin.nav.customers', 'Customers') },
                    { label: t('admin.questions.questions', 'Questions') },
                ]}
                actions={(
                    <ListSettings
                        listUrl="/admin/questions"
                        importUrl="/admin/questions/import"
                        importSampleUrl="/admin/questions/import/sample"
                        exportUrl="/admin/questions/export"
                        query={query}
                    />
                )}
            />
            <ListPanel
                tabs={<StatusTabs tabs={tabs()} value={query.status} onChange={(status) => apply({ status })} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'approve',
                                label: t('admin.questions.approve', 'Approve'),
                                icon: 'check',
                                onClick: () => runBulk('approve'),
                            },
                            {
                                key: 'unapprove',
                                label: t('admin.questions.unapprove', 'Unapprove'),
                                icon: 'arrow-left',
                                onClick: () => runBulk('unapprove'),
                            },
                            {
                                key: 'reject',
                                label: t('admin.questions.reject', 'Reject'),
                                icon: 'ban',
                                onClick: () => runBulk('reject'),
                            },
                            {
                                key: 'delete',
                                label: t('admin.products.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.questions.delete_selected', 'Delete selected questions?'),
                                onClick: () => runBulk('delete'),
                            },
                        ]}
                    />
                )}
                footer={(
                    <ListFooter
                        pagination={questions}
                        query={query}
                    />
                )}
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
                            <SortHeader column="product" label={t('admin.questions.product', 'Product')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.questions.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="question" label={t('admin.questions.question', 'Question')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="status" label={t('admin.questions.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Actions')}</Th>
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
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="product" value={filters.product || ''} placeholder={t('admin.questions.product', 'Product')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="customer" value={filters.customer || ''} placeholder={t('admin.questions.customer', 'Customer')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="question" value={filters.question || ''} placeholder={t('admin.questions.question', 'Question')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    type="select"
                                    name="status"
                                    value={filters.status === 'all' ? '' : (filters.status || '')}
                                    placeholder={t('admin.questions.status', 'Status')}
                                    options={[
                                        { value: 'pending', label: t('admin.questions.pending', 'Pending') },
                                        { value: 'approved', label: t('admin.questions.approved', 'Approved') },
                                        { value: 'rejected', label: t('admin.questions.rejected', 'Rejected') },
                                    ]}
                                    onApply={(name, value) => apply({ [name]: value || 'all' })}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/questions" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>{t('admin.questions.no_questions_yet', 'No questions yet.')}</EmptyState>
                        ) : rows.map((row) => {
                            const status = row.status || 'pending';
                            const local = draft[row.id] || { reason: row.rejected_reason || '', answer: '' };
                            return (
                                <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(row.id)}
                                            onChange={() => selection.toggle(row.id)}
                                            ariaLabel={`${t('admin.common.select', 'Select')} question ${row.id}`}
                                        />
                                    </Td>
                                    <Td col="id">{row.id}</Td>
                                    <Td>
                                        {row.product?.id ? (
                                            <Button variant="link" href={`/admin/products/${row.product.id}/edit`}>{row.product.name || '—'}</Button>
                                        ) : (
                                            row.product?.name || '—'
                                        )}
                                    </Td>
                                    <Td>
                                        {row.customer?.id ? (
                                            <Button variant="link" href={`/admin/customers/${row.customer.id}`}>{row.customer.name || '—'}</Button>
                                        ) : (
                                            <div>{row.customer?.name || '—'}</div>
                                        )}
                                        <div className="text-xs text-neutral-500">{row.customer?.email || ''}</div>
                                    </Td>
                                    <Td>
                                        <p className="max-w-md text-sm">{row.body}</p>
                                        {(row.answers || []).map((answer) => (
                                            <p key={answer.id} className="mt-2 text-sm text-violet-800">
                                                {t('admin.questions.answer', 'Answer')}: {answer.body}
                                            </p>
                                        ))}
                                        {status !== 'approved' ? (
                                            <Input
                                                className="mt-2"
                                                wide
                                                placeholder={t('admin.questions.reject_reason', 'Reject reason (optional)')}
                                                value={local.reason}
                                                onChange={(e) => setDraft((d) => ({ ...d, [row.id]: { ...local, reason: e.target.value } }))}
                                            />
                                        ) : null}
                                        <Textarea
                                            className="mt-2"
                                            rows={2}
                                            placeholder={t('admin.questions.answer_placeholder', 'Official answer from the shop')}
                                            value={local.answer}
                                            onChange={(e) => setDraft((d) => ({ ...d, [row.id]: { ...local, answer: e.target.value } }))}
                                        />
                                    </Td>
                                    <Td>
                                        <Badge tone={tone[status] || 'neutral'}>
                                            {status === 'approved' ? t('admin.questions.approved', 'Approved') : status === 'rejected' ? t('admin.questions.rejected', 'Rejected') : t('admin.questions.pending', 'Pending')}
                                        </Badge>
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            canEdit={false}
                                            confirmMessage={t('admin.questions.delete_confirm', 'Delete this question?')}
                                            onDelete={() => moderate(row.id, { action: 'delete' })}
                                            items={[
                                                status !== 'approved'
                                                    ? {
                                                        key: 'approve',
                                                        label: t('admin.questions.approve', 'Approve'),
                                                        icon: 'check',
                                                        onClick: () => moderate(row.id, { action: 'approve' }),
                                                    }
                                                    : {
                                                        key: 'unapprove',
                                                        label: t('admin.questions.unapprove', 'Unapprove'),
                                                        icon: 'arrow-left',
                                                        onClick: () => moderate(row.id, { action: 'pending' }),
                                                    },
                                                status !== 'rejected'
                                                    ? {
                                                        key: 'reject',
                                                        label: t('admin.questions.reject', 'Reject'),
                                                        onClick: () => moderate(row.id, { action: 'reject', rejected_reason: local.reason }),
                                                    }
                                                    : null,
                                                {
                                                    key: 'answer',
                                                    label: t('admin.questions.publish_answer', 'Publish answer'),
                                                    onClick: () => moderate(row.id, { action: 'answer', body: local.answer }),
                                                },
                                            ]}
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
