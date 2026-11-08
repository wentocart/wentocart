import { router } from '@inertiajs/react';
import Layout from '../Layout';
import { Badge, Button, Card, PageHeader, ui, cx, t } from '../../ui';
import HealthShell, { healthScoreDisk, healthSeverityTone } from './HealthShell';

export default function Overview({
    has_scan: hasScan,
    score,
    score_label: scoreLabel,
    delta_30: delta,
    critical = 0,
    warning = 0,
    opportunity = 0,
    passed = 0,
    categories = [],
    recent_issues: recent = [],
    last_scan: lastScan,
    live,
}) {
    const runScan = () => {
        router.post('/admin/health/scan', { type: 'full' });
    };

    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.title', 'Store Health')}
                    description={t('admin.health.desc', 'Monitor your store\'s SEO, content, images, performance and technical health from one place. Find problems, understand their impact and fix supported issues directly from WentoCart.')}
                    actions={(
                        <>
                            <Button href="/admin/health/history" variant="secondary">{t('admin.health.scan_history', 'Scan History')}</Button>
                            <Button href="/admin/health/settings" variant="secondary">{t('admin.health.settings', 'Settings')}</Button>
                            <Button type="button" icon="refresh" onClick={runScan}>{t('admin.health.run_scan', 'Run Full Scan')}</Button>
                        </>
                    )}
                />
                {lastScan ? (
                    <p className={`${ui.muted} mb-4`}>
                        {t('admin.health.last_scan', 'Last full scan')}: {lastScan.at}
                        {' · '}
                        {lastScan.pages} {t('admin.health.pages_checked', 'pages checked')}
                        {' · '}
                        {lastScan.assets} {t('admin.health.assets_analyzed', 'assets analyzed')}
                    </p>
                ) : (
                    <Card className="mb-4">
                        <p className="font-medium">{t('admin.health.first_scan_title', 'Your store has not been analyzed yet')}</p>
                        <p className={`${ui.muted} mb-3`}>{t('admin.health.first_scan_hint', 'Run your first Store Health scan to check SEO, content, images, performance and technical configuration.')}</p>
                        <Button type="button" onClick={runScan}>{t('admin.health.run_first', 'Run First Scan')}</Button>
                    </Card>
                )}
                {live && (live.status === 'running' || live.status === 'queued') ? (
                    <Card className="mb-4">
                        <p className="font-medium">{t('admin.health.scanning', 'Scanning your store…')}</p>
                        <p className={ui.muted}>{live.current_step}</p>
                        <Button href={`/admin/health/scan/${live.id}`} variant="secondary" className="mt-2">{t('admin.health.view_progress', 'View progress')}</Button>
                    </Card>
                ) : null}
                <div className="mb-5 grid gap-4 lg:grid-cols-[220px_1fr]">
                    <Card className="flex flex-col items-center justify-center text-center">
                        <div className={cx(ui.scoreDisk, healthScoreDisk(score))}>{score ?? '—'}</div>
                        <p className="mt-2 font-semibold">{scoreLabel}</p>
                        {delta != null ? (
                            <p className={ui.muted}>{delta >= 0 ? '+' : ''}{delta} {t('admin.health.since_previous', 'since previous scan')}</p>
                        ) : null}
                    </Card>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            { label: t('admin.health.critical', 'Critical'), value: critical, tone: 'danger' },
                            { label: t('admin.health.warnings', 'Warnings'), value: warning, tone: 'warning' },
                            { label: t('admin.health.opportunities', 'Opportunities'), value: opportunity, tone: 'info' },
                            { label: t('admin.health.passed', 'Passed'), value: passed, tone: 'success' },
                        ].map((item) => (
                            <Card key={item.label}>
                                <p className={ui.muted}>{item.label}</p>
                                <p className="text-2xl font-semibold tabular-nums">{item.value}</p>
                                <Badge tone={item.tone}>{item.label}</Badge>
                            </Card>
                        ))}
                    </div>
                </div>
                <h2 className={`${ui.sectionTitle} mb-3`}>{t('admin.health.by_category', 'Health by category')}</h2>
                <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {categories.map((cat) => (
                        <a key={cat.id} href={`/admin/health/${({ seo: 'seo', content: 'content', images: 'images', performance: 'performance', technical: 'technical', cleanup: 'cleanup' })[cat.id] || cat.id}`} className={ui.scoreCard}>
                            <div className={cx(ui.scoreDisk, 'h-16 w-16 border-4 text-lg', healthScoreDisk(cat.score))}>{cat.score ?? '—'}</div>
                            <div className="font-medium">{cat.title}</div>
                            <p className={ui.muted}>{cat.open} {t('admin.health.open_short', 'open')} · {cat.checks} {t('admin.health.checks', 'checks')}</p>
                            <p className={`${ui.muted} text-xs`}>{cat.hint}</p>
                        </a>
                    ))}
                </div>
                <div className="mb-4 flex flex-wrap gap-2">
                    <Button href="/admin/health/issues" variant="secondary">{t('admin.health.view_all_issues', 'View All Issues')}</Button>
                    <Button href="/admin/health/fix-center" variant="secondary">{t('admin.health.open_fix_center', 'Open Fix Center')}</Button>
                    <Button href="/admin/health/history" variant="secondary">{t('admin.health.view_history', 'View History')}</Button>
                </div>
                <h2 className={`${ui.sectionTitle} mb-3`}>{t('admin.health.recent_issues', 'Recent issues')}</h2>
                <div className="space-y-3">
                    {recent.length ? recent.map((row) => (
                        <Card key={row.id} className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="mb-1 flex flex-wrap gap-2">
                                    <Badge tone={healthSeverityTone(row.severity)}>{row.severity}</Badge>
                                    <span className={ui.muted}>{row.category}</span>
                                    {row.affected_count > 0 ? <span className={ui.muted}>{row.affected_count}</span> : null}
                                </div>
                                <p className="font-medium">{row.title}</p>
                                <p className={ui.muted}>{row.detail}</p>
                            </div>
                            <Button href={`/admin/health/issues/${row.id}`} variant="secondary">{t('admin.common.view', 'View')}</Button>
                        </Card>
                    )) : (
                        <Card>
                            <p className="font-medium">{hasScan ? t('admin.health.no_open', 'No open issues') : t('admin.health.run_to_start', 'Run a scan to start')}</p>
                        </Card>
                    )}
                </div>
            </HealthShell>
        </Layout>
    );
}
