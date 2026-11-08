import Layout from '../Layout';
import { Badge, Button, Card, PageHeader, ui, cx, t } from '../../ui';
import HealthShell, { healthScoreDisk, healthSeverityTone } from './HealthShell';

function CategoryView({ title, hint, category, issues = [], extra }) {
    return (
        <Layout>
            <HealthShell>
                <PageHeader title={title} description={hint} actions={<Button href="/admin/health/fix-center" variant="secondary">{t('admin.health.open_fix_center', 'Open Fix Center')}</Button>} />
                <div className="mb-5 flex flex-wrap items-center gap-4">
                    <div className={cx(ui.scoreDisk, healthScoreDisk(category?.score))}>{category?.score ?? '—'}</div>
                    <div>
                        <p className="font-semibold">{category?.label || t('admin.health.not_scanned', 'Not scanned')}</p>
                        <p className={ui.muted}>{category?.open ?? 0} {t('admin.health.open_short', 'open')} · {category?.checks ?? 0} {t('admin.health.checks', 'checks')}</p>
                    </div>
                </div>
                {extra}
                <div className="space-y-3">
                    {issues.length ? issues.map((row) => (
                        <Card key={row.id} className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="mb-1 flex gap-2"><Badge tone={healthSeverityTone(row.severity)}>{row.severity}</Badge><span className={ui.muted}>{row.affected_count || ''}</span></div>
                                <p className="font-medium">{row.title}</p>
                                <p className={ui.muted}>{row.recommendation}</p>
                            </div>
                            <Button href={`/admin/health/issues/${row.id}`} variant="secondary">{t('admin.common.view', 'View')}</Button>
                        </Card>
                    )) : <Card><p>{t('admin.health.no_open', 'No open issues')}</p></Card>}
                </div>
            </HealthShell>
        </Layout>
    );
}

export function Seo({ overview, seo, issues }) {
    return (
        <CategoryView
            title={t('admin.health.seo_center', 'SEO Center')}
            hint={t('admin.health.seo_hint', 'Search visibility, metadata, indexing and structured data.')}
            category={seo}
            issues={issues}
            extra={(
                <>
                    <div className="mb-4 flex flex-wrap gap-2">
                        <Button href="/admin/seo" variant="secondary">{t('admin.health.open_seo_studio', 'Open SEO studio')}</Button>
                        <Button href="/admin/seo?tab=pages" variant="secondary">{t('admin.health.edit_page_meta', 'Edit page meta')}</Button>
                    </div>
                    <div className="mb-4 grid gap-3 sm:grid-cols-4">
                        <Card><p className={ui.muted}>{t('admin.health.products', 'Products')}</p><p className="text-xl font-semibold">{overview?.counts?.products ?? 0}</p></Card>
                        <Card><p className={ui.muted}>{t('admin.health.categories', 'Categories')}</p><p className="text-xl font-semibold">{overview?.counts?.categories ?? 0}</p></Card>
                        <Card><p className={ui.muted}>{t('admin.health.cms', 'CMS pages')}</p><p className="text-xl font-semibold">{overview?.counts?.cms ?? 0}</p></Card>
                        <Card><p className={ui.muted}>{t('admin.health.open_short', 'open')}</p><p className="text-xl font-semibold">{seo?.open ?? 0}</p></Card>
                    </div>
                </>
            )}
        />
    );
}

export function Content({ category, issues }) {
    return <CategoryView title={t('admin.health.content_center', 'Content Health')} hint={t('admin.health.content_hint', 'Product and CMS content quality.')} category={category} issues={issues} />;
}

export function Performance({ category, issues }) {
    return (
        <CategoryView
            title={t('admin.health.perf_center', 'Performance')}
            hint={t('admin.health.perf_hint', 'Frontend weight and configuration WentoCart can detect. Core Web Vitals are shown only when measured — not invented.')}
            category={category}
            issues={issues}
            extra={<Card className="mb-4"><p className={ui.muted}>{t('admin.health.cwv_na', 'LCP / INP / CLS: not yet measured on this host. Use real RUM later; do not trust fabricated scores.')}</p></Card>}
        />
    );
}

export function Technical({ category, issues }) {
    return <CategoryView title={t('admin.health.tech_center', 'Technical')} hint={t('admin.health.tech_hint', 'HTTPS, sitemap, robots, mail, payments and configuration.')} category={category} issues={issues} />;
}

export function Cleanup({ category, issues }) {
    return <CategoryView title={t('admin.health.cleanup_center', 'Store Cleanup')} hint={t('admin.health.cleanup_hint', 'Review unused or stale data before deleting. Never blind-delete.')} category={category} issues={issues} />;
}

export default function ContentPage(props) {
    return <Content {...props} />;
}
