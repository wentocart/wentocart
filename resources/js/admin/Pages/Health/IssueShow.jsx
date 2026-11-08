import { router } from '@inertiajs/react';
import Layout from '../Layout';
import { Badge, Button, Card, PageHeader, ui, t } from '../../ui';
import HealthShell, { healthSeverityTone } from './HealthShell';

export default function IssueShow({ finding, rule }) {
    const caps = finding.fix_capabilities || [];
    const canAuto = caps.includes('AUTO_FIX') || caps.includes('BULK_FIX');
    const canAi = caps.includes('AI_FIX') || caps.includes('AI_BULK_FIX');

    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={finding.name || finding.title}
                    crumbs={[
                        { href: '/admin/health', label: t('admin.health.title', 'Store Health') },
                        { href: '/admin/health/issues', label: t('admin.health.issues_title', 'Issues') },
                        { label: `#${finding.id}` },
                    ]}
                    actions={(
                        <>
                            {canAuto ? (
                                <Button type="button" onClick={() => router.post(`/admin/health/issues/${finding.id}/fix`)}>
                                    {t('admin.health.fix_auto', 'Fix automatically')}
                                </Button>
                            ) : null}
                            {canAi ? (
                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/health/issues/${finding.id}/ai`)}>
                                    {t('admin.health.fix_ai', 'Fix with AI')}
                                </Button>
                            ) : null}
                            <Button type="button" variant="secondary" onClick={() => router.post(`/admin/health/issues/${finding.id}/ignore`, { mode: 'snooze_7' })}>
                                {t('admin.health.snooze', 'Snooze 7 days')}
                            </Button>
                        </>
                    )}
                />
                <div className="mb-4 flex flex-wrap gap-2">
                    <Badge tone={healthSeverityTone(finding.severity)}>{finding.severity}</Badge>
                    <Badge>{finding.category}</Badge>
                    {(caps).map((c) => <Badge key={c} tone="neutral">{c}</Badge>)}
                    {finding.affected_count > 0 ? <Badge tone="info">{finding.affected_count} affected</Badge> : null}
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <h3 className={ui.sectionTitle}>{t('admin.health.what_found', 'What we found')}</h3>
                        <p>{finding.title}</p>
                        <p className={`${ui.muted} mt-2`}>{finding.detail}</p>
                    </Card>
                    <Card>
                        <h3 className={ui.sectionTitle}>{t('admin.health.why_matters', 'Why it matters')}</h3>
                        <p>{finding.impact || rule?.impact}</p>
                    </Card>
                    <Card>
                        <h3 className={ui.sectionTitle}>{t('admin.health.recommended', 'Recommended fix')}</h3>
                        <p>{finding.recommendation || rule?.recommendation}</p>
                    </Card>
                    <Card>
                        <h3 className={ui.sectionTitle}>{t('admin.health.samples', 'Examples')}</h3>
                        {(finding.samples || []).length ? (
                            <ul className="list-disc pl-5 text-sm">{finding.samples.map((s) => <li key={s}>{s}</li>)}</ul>
                        ) : (
                            <p className={ui.muted}>{t('admin.health.no_samples', 'No sample rows for this check.')}</p>
                        )}
                    </Card>
                </div>
            </HealthShell>
        </Layout>
    );
}
