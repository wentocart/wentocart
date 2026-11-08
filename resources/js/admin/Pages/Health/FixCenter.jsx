import { router } from '@inertiajs/react';
import Layout from '../Layout';
import { Badge, Button, Card, PageHeader, ui, t } from '../../ui';
import HealthShell, { healthSeverityTone } from './HealthShell';

function Group({ title, rows }) {
    if (!rows?.length) return null;
    return (
        <div className="mb-6">
            <h2 className={`${ui.sectionTitle} mb-3`}>{title} ({rows.length})</h2>
            <div className="space-y-3">
                {rows.map((row) => (
                    <Card key={row.id} className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="mb-1 flex gap-2">
                                <Badge tone={healthSeverityTone(row.severity)}>{row.severity}</Badge>
                                <span className={ui.muted}>{row.category}</span>
                            </div>
                            <p className="font-medium">{row.title}</p>
                            <p className={ui.muted}>{row.recommendation}</p>
                        </div>
                        <div className="flex gap-2">
                            {(row.fix_capabilities || []).includes('AUTO_FIX') || (row.fix_capabilities || []).includes('BULK_FIX') ? (
                                <Button type="button" onClick={() => router.post(`/admin/health/issues/${row.id}/fix`)}>{t('admin.seo.fix', 'Fix')}</Button>
                            ) : null}
                            {(row.fix_capabilities || []).includes('AI_FIX') || (row.fix_capabilities || []).includes('AI_BULK_FIX') ? (
                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/health/issues/${row.id}/ai`)}>{t('admin.health.fix_ai', 'Fix with AI')}</Button>
                            ) : null}
                            <Button href={`/admin/health/issues/${row.id}`} variant="secondary">{t('admin.common.view', 'View')}</Button>
                        </div>
                    </Card>
                ))}
            </div>
        </div>
    );
}

export default function FixCenter({ groups = {} }) {
    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.fix_center', 'Fix Center')}
                    description={t('admin.health.fix_center_hint', 'What can you actually do? Safe auto-fixes, AI previews, bulk updates, and manual configuration.')}
                />
                <Group title={t('admin.health.quick_fixes', 'Quick Fixes')} rows={groups.quick} />
                <Group title={t('admin.health.ai_fixes', 'AI Fixes')} rows={groups.ai} />
                <Group title={t('admin.health.bulk_fixes', 'Bulk Fixes')} rows={groups.bulk} />
                <Group title={t('admin.health.manual_fixes', 'Manual Fixes')} rows={groups.manual} />
                {!groups.quick?.length && !groups.ai?.length && !groups.bulk?.length && !groups.manual?.length ? (
                    <Card><p>{t('admin.health.no_open', 'No open issues')}</p></Card>
                ) : null}
            </HealthShell>
        </Layout>
    );
}
