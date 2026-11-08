import { Link } from '@inertiajs/react';
import Layout from '../Layout';
import { Badge, Card, EmptyState, ListPanel, PageHeader, Pagination, Table, THead, Th, Tr, Td, ui, t } from '../../ui';
import HealthShell from './HealthShell';

export default function History({ scans, fixes = [], trend = [] }) {
    const rows = scans?.data || [];

    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.history', 'History')}
                    description={t('admin.health.history_page_hint', 'Scan history and applied fixes. Score trend shows whether the store is improving.')}
                />
                {trend.length ? (
                    <Card className="mb-4">
                        <p className="mb-2 font-medium">{t('admin.health.score_trend', 'Score trend')}</p>
                        <p className={ui.muted}>{trend.map((p) => `${p.date}: ${p.score}`).join(' → ')}</p>
                    </Card>
                ) : null}
                <h2 className={`${ui.sectionTitle} mb-2`}>{t('admin.health.scan_history', 'Scan History')}</h2>
                <ListPanel>
                    <Table>
                        {rows.length === 0 ? (
                            <tbody><EmptyState colSpan={6}>{t('admin.health.no_scans', 'No scans yet')}</EmptyState></tbody>
                        ) : (
                            <>
                                <THead>
                                    <Th>{t('admin.activity.when', 'When')}</Th>
                                    <Th>{t('admin.health.score', 'Score')}</Th>
                                    <Th>{t('admin.health.critical', 'Critical')}</Th>
                                    <Th>{t('admin.health.warnings', 'Warnings')}</Th>
                                    <Th>{t('admin.activity.status', 'Status')}</Th>
                                    <Th>{t('admin.common.actions', 'Actions')}</Th>
                                </THead>
                                <tbody>
                                    {rows.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>{row.completed_at || row.started_at}</Td>
                                            <Td>{row.overall_score ?? '—'}</Td>
                                            <Td>{row.critical_count}</Td>
                                            <Td>{row.warning_count}</Td>
                                            <Td><Badge>{row.status}</Badge></Td>
                                            <Td><Link href={`/admin/health/scan/${row.id}`}>{t('admin.common.view', 'View')}</Link></Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </>
                        )}
                    </Table>
                </ListPanel>
                <Pagination paginator={scans} />
                <h2 className={`${ui.sectionTitle} mt-6 mb-2`}>{t('admin.health.fix_history', 'Fix History')}</h2>
                <div className="space-y-2">
                    {fixes.map((f) => (
                        <Card key={f.id} className="flex justify-between gap-3">
                            <div>
                                <p className="font-medium">{f.rule_code}</p>
                                <p className={ui.muted}>{f.fix_type} · {f.created_at}</p>
                            </div>
                            <Badge>{f.status}</Badge>
                        </Card>
                    ))}
                </div>
            </HealthShell>
        </Layout>
    );
}
