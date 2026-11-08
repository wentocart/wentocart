import { router } from '@inertiajs/react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    Badge,
    Button,
    ColumnFilter,
    EmptyState,
    FilterReset,
    Input,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    t,
    ui,
} from '../../ui';

const tone = {
    pending: 'warning',
    completed: 'success',
};

const statusLabel = {
    pending: 'Pending',
    completed: 'Completed',
};

function tabs() {
    return [
        { key: 'all', label: t('admin.gdpr.all', 'All') },
        { key: 'pending', label: t('admin.gdpr.pending', 'Pending') },
        { key: 'completed', label: t('admin.gdpr.completed', 'Completed') },
    ];
}

const statusOptions = Object.entries(statusLabel).map(([value, label]) => ({ value, label }));

export default function GdprRequests({ requests, filters = {} }) {
    const rows = requests.data || [];
    const query = {
        reference: filters.reference || '',
        customer: filters.customer || '',
        message: filters.message || '',
        status: filters.status || 'all',
        type: filters.type || '',
        created_from: filters.created_from || '',
        created_to: filters.created_to || '',
    };

    const apply = (next) => {
        const payload = { ...query, ...next, page: 1 };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/gdpr-requests', payload, { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyColumn = (name, value) => apply({ [name]: value });

    return (
        <Layout>
            <PageHeader
                title={t('admin.gdpr.requests', 'GDPR Data Requests')}
                total={requests.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customers', label: t('admin.nav.customers', 'Customers') },
                    { label: t('admin.gdpr.requests', 'GDPR Data Requests') },
                ]}
                crumbsFirst
                meta={t('admin.gdpr.requests_hint', 'Review customer deletion requests. Completed erases the account and keeps the request. Declined leaves the account as it is.')}
                actions={(
                    <Button variant="secondary" onClick={() => { window.location.assign(adminUrl('/admin/gdpr-requests/export')); }}>
                        {t('admin.gdpr.export', 'Export')}
                    </Button>
                )}
            />
            <ListPanel
                tabs={(
                    <StatusTabs
                        tabs={tabs()}
                        value={query.status}
                        onChange={(status) => apply({ status })}
                    />
                )}
                footer={(
                    <ListFooter pagination={requests} />
                )}
            >
                <Table>
                    <THead>
                        <Tr>
                            <Th>{t('admin.gdpr.col_reference', 'Reference')}</Th>
                            <Th>{t('admin.gdpr.col_customer', 'Customer name')}</Th>
                            <Th>{t('admin.gdpr.col_status', 'Status')}</Th>
                            <Th>{t('admin.gdpr.col_type', 'Type')}</Th>
                            <Th>{t('admin.gdpr.col_change', 'Requested change')}</Th>
                            <Th>{t('admin.gdpr.col_message', 'Message')}</Th>
                            <Th>{t('admin.gdpr.col_created', 'Created at')}</Th>
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </Tr>
                        <Tr>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="reference" value={query.reference} placeholder={t('admin.gdpr.search_reference', 'Search reference')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="customer" value={query.customer} placeholder={t('admin.gdpr.search_customer', 'Search customer')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    type="select"
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    placeholder={t('admin.gdpr.all_statuses', 'All statuses')}
                                    options={statusOptions}
                                    onApply={(name, value) => apply({ status: value || 'all' })}
                                />
                            </Th>
                            <Th />
                            <Th />
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="message" value={query.message} placeholder={t('admin.gdpr.search_message', 'Search message')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <div className="flex flex-col gap-1">
                                    <Input type="date" value={query.created_from} onChange={(e) => apply({ created_from: e.target.value })} />
                                    <Input type="date" value={query.created_to} onChange={(e) => apply({ created_to: e.target.value })} />
                                </div>
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/gdpr-requests" />
                            </Th>
                        </Tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.gdpr.empty', 'No GDPR data requests yet.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id}>
                                <Td>{row.reference || `#${row.id}`}</Td>
                                <Td>{row.customer_name}</Td>
                                <Td><Badge tone={tone[row.status] || 'neutral'}>{statusLabel[row.status] || row.status}</Badge></Td>
                                <Td>{row.type_label}</Td>
                                <Td>{row.summary || '—'}</Td>
                                <Td>{row.message}</Td>
                                <Td>{row.created_at ? new Date(row.created_at).toLocaleString() : ''}</Td>
                                <Td>
                                    <RowActions
                                        editHref={`/admin/gdpr-requests/${row.id}`}
                                        confirmMessage={t('admin.gdpr.delete_confirm', 'Delete this GDPR request? The customer account stays.')}
                                        onDelete={() => router.delete(`/admin/gdpr-requests/${row.id}`)}
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
