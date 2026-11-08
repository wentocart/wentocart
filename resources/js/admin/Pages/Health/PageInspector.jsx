import { router } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import { Button, Card, Input, PageHeader, ui, t } from '../../ui';
import HealthShell, { healthScoreDisk } from './HealthShell';
import { cx } from '../../ui';

export default function PageInspector({ q = '', page }) {
    const [value, setValue] = useState(q);

    return (
        <Layout>
            <HealthShell>
                <PageHeader
                    title={t('admin.health.inspector', 'Page Inspector')}
                    description={t('admin.health.inspector_hint', 'Inspect a product by ID or SKU. Deeper URL crawl comes later.')}
                />
                <form className="mb-4 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/admin/health/inspector', { q: value }); }}>
                    <Input value={value} onChange={(e) => setValue(e.target.value)} placeholder={t('admin.health.inspector_placeholder', 'Product ID or SKU')} />
                    <Button type="submit">{t('admin.health.analyze', 'Analyze')}</Button>
                </form>
                {page ? (
                    <Card>
                        <div className="mb-3 flex items-center gap-4">
                            <div className={cx(ui.scoreDisk, 'h-16 w-16 border-4 text-lg', healthScoreDisk(page.score))}>{page.score}</div>
                            <div>
                                <p className="font-semibold">{page.label}</p>
                                <p className={ui.muted}>{page.type} · {page.sku}</p>
                            </div>
                        </div>
                        <ul className="space-y-1 text-sm">
                            {Object.entries(page.checks || {}).map(([key, ok]) => (
                                <li key={key}>{ok ? '✓' : '!'} {key}</li>
                            ))}
                        </ul>
                        <Button href={page.href} variant="secondary" className="mt-3">{t('admin.health.open_resource', 'Open in admin')}</Button>
                    </Card>
                ) : (
                    <Card><p className={ui.muted}>{t('admin.health.inspector_empty', 'Enter a product ID or SKU to inspect.')}</p></Card>
                )}
            </HealthShell>
        </Layout>
    );
}
