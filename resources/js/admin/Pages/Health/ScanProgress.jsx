import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import Layout from '../Layout';
import { Badge, Button, Card, PageHeader, ui, cx, t } from '../../ui';
import HealthShell, { healthScoreDisk } from './HealthShell';

export default function ScanProgress({ scan }) {
    useEffect(() => {
        if (!scan || scan.status === 'completed' || scan.status === 'failed') {
            return undefined;
        }
        const id = window.setInterval(() => {
            router.reload({ only: ['scan'] });
        }, 2000);
        return () => window.clearInterval(id);
    }, [scan?.status, scan?.id]);

    const done = scan?.status === 'completed';
    const failed = scan?.status === 'failed';

    return (
        <Layout>
            <HealthShell>
                <PageHeader title={done ? t('admin.health.scan_complete', 'Scan Complete') : t('admin.health.scanning', 'Scanning your store')} />
                <Card className="mb-4 flex flex-col items-center text-center">
                    <div className={cx(ui.scoreDisk, healthScoreDisk(scan?.overall_score))}>
                        {done ? (scan.overall_score ?? '—') : (scan?.status === 'running' ? '…' : '5%')}
                    </div>
                    <p className="mt-2 font-medium">{scan?.current_step}</p>
                    <Badge className="mt-2">{scan?.status}</Badge>
                    {failed ? <p className="mt-2 text-rose-600">{scan.error}</p> : null}
                </Card>
                {done ? (
                    <div className="mb-4 grid gap-3 sm:grid-cols-4">
                        <Card><p className={ui.muted}>{t('admin.health.critical', 'Critical')}</p><p className="text-xl font-semibold">{scan.critical_count}</p></Card>
                        <Card><p className={ui.muted}>{t('admin.health.warnings', 'Warnings')}</p><p className="text-xl font-semibold">{scan.warning_count}</p></Card>
                        <Card><p className={ui.muted}>{t('admin.health.opportunities', 'Opportunities')}</p><p className="text-xl font-semibold">{scan.opportunity_count}</p></Card>
                        <Card><p className={ui.muted}>{t('admin.health.passed', 'Passed')}</p><p className="text-xl font-semibold">{scan.passed_count}</p></Card>
                    </div>
                ) : null}
                {scan?.comparison ? (
                    <Card className="mb-4">
                        <p>{t('admin.health.previous_score', 'Previous score')}: {scan.comparison.previous_score}</p>
                        <p>{t('admin.health.current_score', 'Current score')}: {scan.comparison.current_score} ({scan.comparison.delta >= 0 ? '+' : ''}{scan.comparison.delta})</p>
                    </Card>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    <Button href="/admin/health/issues">{t('admin.health.view_all_issues', 'View All Issues')}</Button>
                    <Button href="/admin/health/fix-center" variant="secondary">{t('admin.health.open_fix_center', 'Open Fix Center')}</Button>
                    <Button href="/admin/health" variant="secondary">{t('admin.health.back', 'Back to Overview')}</Button>
                </div>
            </HealthShell>
        </Layout>
    );
}
