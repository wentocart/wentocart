import { Link, router } from '@inertiajs/react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    EmptyState,
    ListPanel,
    PageHeader,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../../ui';

function statusTone(status) {
    if (status === 'completed') return 'success';
    if (status === 'failed') return 'danger';
    if (status === 'rolled_back') return 'warning';
    if (status === 'previewed') return 'info';
    return 'neutral';
}

export default function Index({ operations, filters = {} }) {
    const rows = operations.data || [];

    return (
        <Layout>
            <PageHeader
                title={t('admin.activity.title', 'Activity')}
                description={t('admin.activity.hint', 'Staff and Ask AI changes you can undo. Store Health Fix history stays on Operate → Store health. Import studio and Automations are not in this list.')}
            />
            <div className="mb-3 flex gap-2">
                <Select value={filters.source || ''} onChange={(e) => router.get('/admin/activity', { ...filters, source: e.target.value }, { preserveState: true })}>
                    <option value="">{t('admin.activity.all_sources', 'All sources')}</option>
                    {['admin', 'ai'].map((source) => (
                        <option key={source} value={source}>{source}</option>
                    ))}
                </Select>
                <Select value={filters.status || ''} onChange={(e) => router.get('/admin/activity', { ...filters, status: e.target.value }, { preserveState: true })}>
                    <option value="">{t('admin.activity.all_status', 'All statuses')}</option>
                    {['previewed', 'completed', 'failed', 'rolled_back'].map((status) => (
                        <option key={status} value={status}>{status}</option>
                    ))}
                </Select>
            </div>
            <ListPanel>
                <Table>
                    {rows.length === 0 ? (
                        <tbody>
                            <EmptyState colSpan={5}>
                                {t('admin.activity.empty', 'No staff or AI changes yet. Editing a product, customer, order, brand, category, or CMS page in admin is recorded here when it can be rolled back.')}
                            </EmptyState>
                        </tbody>
                    ) : (
                        <>
                            <THead>
                                <Th>{t('admin.activity.when', 'When')}</Th>
                                <Th>{t('admin.activity.summary', 'Summary')}</Th>
                                <Th>{t('admin.activity.source', 'Source')}</Th>
                                <Th>{t('admin.activity.status', 'Status')}</Th>
                                <Th>{t('admin.common.actions', 'Actions')}</Th>
                            </THead>
                            <tbody>
                                {rows.map((row) => (
                                    <Tr key={row.id}>
                                        <Td>{row.created_at}</Td>
                                        <Td>
                                            <Link href={`/admin/activity/${row.id}`}>{row.summary}</Link>
                                            <div className={ui.muted}>{row.items_count} {t('admin.activity.records', 'records')}</div>
                                        </Td>
                                        <Td>{row.source}</Td>
                                        <Td><Badge tone={statusTone(row.status)}>{row.status}</Badge></Td>
                                        <Td>
                                            {row.can_rollback ? (
                                                <Button variant="secondary" type="button" onClick={() => router.post(`/admin/activity/${row.id}/rollback`)}>
                                                    {t('admin.activity.rollback', 'Roll back')}
                                                </Button>
                                            ) : null}
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </>
                    )}
                </Table>
            </ListPanel>
        </Layout>
    );
}
