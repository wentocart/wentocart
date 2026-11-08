import { Link, router } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Badge, Button, EmptyState, ListPanel, PageHeader, Pagination, StatusTabs, Table, THead, Th, Tr, Td, ui, t,
} from '../../ui';
import HealthShell, { healthSeverityTone } from './HealthShell';

export default function Issues({ findings, counts = {}, filters = {}, categories = [] }) {
    const rows = findings?.data || [];
    const severity = filters.severity || 'all';

    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.issues_title', 'Issues & Opportunities')}
                    description={t('admin.health.issues_hint', 'Every finding includes severity, fix type, and a path to review or repair.')}
                />
                <StatusTabs
                    value={severity}
                    onChange={(key) => router.get('/admin/health/issues', { ...filters, severity: key === 'all' ? '' : key, status: key === 'passed' ? 'passed' : 'open' })}
                    tabs={[
                        { key: 'all', label: t('admin.health.all', 'All'), count: counts.all },
                        { key: 'critical', label: t('admin.health.critical', 'Critical'), count: counts.critical },
                        { key: 'warning', label: t('admin.health.warnings', 'Warnings'), count: counts.warning },
                        { key: 'opportunity', label: t('admin.health.opportunities', 'Opportunities'), count: counts.opportunity },
                        { key: 'passed', label: t('admin.health.passed', 'Passed'), count: counts.passed },
                    ]}
                />
                <div className="mb-3 flex flex-wrap gap-2">
                    <select className={ui.select} value={filters.category || ''} onChange={(e) => router.get('/admin/health/issues', { ...filters, category: e.target.value })}>
                        <option value="">{t('admin.health.all_categories', 'All categories')}</option>
                        {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                </div>
                <ListPanel>
                    <Table>
                        {rows.length === 0 ? (
                            <tbody><EmptyState colSpan={6}>{t('admin.health.no_issues', 'No issues in this filter')}</EmptyState></tbody>
                        ) : (
                            <>
                                <THead>
                                    <Th>{t('admin.health.issue', 'Issue')}</Th>
                                    <Th>{t('admin.health.category', 'Category')}</Th>
                                    <Th>{t('admin.health.affected', 'Affected')}</Th>
                                    <Th>{t('admin.health.severity', 'Severity')}</Th>
                                    <Th>{t('admin.health.fix_type', 'Fix type')}</Th>
                                    <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                                </THead>
                                <tbody>
                                    {rows.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>
                                                <Link href={`/admin/health/issues/${row.id}`} className="font-medium">{row.name || row.title}</Link>
                                                <div className={ui.muted}>{row.title}</div>
                                            </Td>
                                            <Td>{row.category}</Td>
                                            <Td>{row.affected_count || '—'}</Td>
                                            <Td><Badge tone={healthSeverityTone(row.severity)}>{row.severity}</Badge></Td>
                                            <Td>{(row.fix_capabilities || []).join(', ') || 'MANUAL'}</Td>
                                            <Td actions>
                                                <Button href={`/admin/health/issues/${row.id}`} variant="secondary">{t('admin.common.view', 'View')}</Button>
                                            </Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </>
                        )}
                    </Table>
                </ListPanel>
                <Pagination paginator={findings} query={filters} />
            </HealthShell>
        </Layout>
    );
}
