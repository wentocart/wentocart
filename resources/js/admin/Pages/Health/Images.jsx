import Layout from '../Layout';
import { Badge, Button, Card, PageHeader, ui, t } from '../../ui';
import HealthShell, { healthSeverityTone } from './HealthShell';

export default function Images({ category, stats = {}, issues = [] }) {
    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.images_center', 'Image Optimization')}
                    description={t('admin.health.images_hint', 'Size, format, ALT text and unused media. Optimize keeps originals when settings say so.')}
                    actions={(
                        <>
                            <Button href="/admin/health/fix-center" variant="secondary">{t('admin.health.open_fix_center', 'Open Fix Center')}</Button>
                            <Button href="/admin/images" variant="secondary">{t('admin.health.open_image_settings', 'Image settings')}</Button>
                        </>
                    )}
                />
                <div className="mb-4 grid gap-3 sm:grid-cols-4">
                    <Card><p className={ui.muted}>{t('admin.health.score', 'Score')}</p><p className="text-2xl font-semibold">{category?.score ?? '—'}</p></Card>
                    <Card><p className={ui.muted}>{t('admin.health.oversized', 'Oversized')}</p><p className="text-2xl font-semibold">{stats.oversized ?? 0}</p></Card>
                    <Card><p className={ui.muted}>{t('admin.health.missing_alt', 'Missing ALT')}</p><p className="text-2xl font-semibold">{stats.missing_alt ?? 0}</p></Card>
                    <Card><p className={ui.muted}>{t('admin.health.unused', 'Unused')}</p><p className="text-2xl font-semibold">{stats.unused ?? 0}</p></Card>
                </div>
                <div className="space-y-3">
                    {issues.map((row) => (
                        <Card key={row.id} className="flex flex-wrap justify-between gap-3">
                            <div>
                                <Badge tone={healthSeverityTone(row.severity)}>{row.severity}</Badge>
                                <p className="mt-1 font-medium">{row.title}</p>
                                <p className={ui.muted}>{row.recommendation}</p>
                            </div>
                            <Button href={`/admin/health/issues/${row.id}`} variant="secondary">{t('admin.common.view', 'View')}</Button>
                        </Card>
                    ))}
                </div>
            </HealthShell>
        </Layout>
    );
}
