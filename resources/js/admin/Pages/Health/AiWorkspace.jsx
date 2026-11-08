import { router } from '@inertiajs/react';
import Layout from '../Layout';
import { Badge, Button, Card, EmptyState, ListPanel, PageHeader, Pagination, Table, THead, Th, Tr, Td, ui, t } from '../../ui';
import HealthShell from './HealthShell';

export default function AiWorkspace({ proposals }) {
    const rows = proposals?.data || [];

    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.ai_workspace', 'AI Optimization Workspace')}
                    description={t('admin.health.ai_workspace_hint', 'Review before/after suggestions. Approve, then apply. AI does not auto-publish by default.')}
                />
                <ListPanel>
                    <Table>
                        {rows.length === 0 ? (
                            <tbody><EmptyState colSpan={5}>{t('admin.health.ai_empty', 'No AI proposals yet. Open an issue and choose Fix with AI.')}</EmptyState></tbody>
                        ) : (
                            <>
                                <THead>
                                    <Th>{t('admin.health.resource', 'Resource')}</Th>
                                    <Th>{t('admin.activity.before', 'Before')}</Th>
                                    <Th>{t('admin.health.suggested', 'Suggested')}</Th>
                                    <Th>{t('admin.activity.status', 'Status')}</Th>
                                    <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                                </THead>
                                <tbody>
                                    {rows.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>{row.resource_type} #{row.resource_id}<div className={ui.muted}>{row.task_type}</div></Td>
                                            <Td><pre className="max-w-xs whitespace-pre-wrap text-xs">{row.current || '—'}</pre></Td>
                                            <Td><pre className="max-w-xs whitespace-pre-wrap text-xs">{row.suggested || '—'}</pre></Td>
                                            <Td><Badge>{row.status}</Badge></Td>
                                            <Td actions>
                                                {row.status === 'generated' ? (
                                                    <Button type="button" variant="secondary" onClick={() => router.post(`/admin/health/ai/${row.id}/approve`)}>{t('admin.health.approve', 'Approve')}</Button>
                                                ) : null}
                                                {row.status === 'approved' || row.status === 'generated' ? (
                                                    <Button type="button" onClick={() => router.post(`/admin/health/ai/${row.id}/apply`)}>{t('admin.health.apply', 'Apply')}</Button>
                                                ) : null}
                                            </Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </>
                        )}
                    </Table>
                </ListPanel>
                <Pagination paginator={proposals} />
            </HealthShell>
        </Layout>
    );
}
