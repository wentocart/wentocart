import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { Button, Card, Checkbox, Input, PageHeader, Select, ui, t } from '../../ui';
import HealthShell from './HealthShell';

export default function Settings({ settings = {} }) {
    const form = useForm({
        enabled: settings.enabled ?? true,
        scan_schedule: settings.scan_schedule || 'weekly',
        notify_critical: settings.notify_critical ?? true,
        notify_score_drop: settings.notify_score_drop ?? true,
        ai_enabled: settings.ai_enabled ?? true,
        ai_require_approval: settings.ai_require_approval ?? true,
        ai_tone: settings.ai_tone || 'professional',
        images_auto_webp: settings.images_auto_webp ?? true,
        images_keep_original: settings.images_keep_original ?? true,
        images_max_edge: settings.images_max_edge || 2048,
        retention_days: settings.retention_days || 90,
    });

    return (
        <Layout>
            <HealthShell>
                <PageHeader title={t('admin.health.settings', 'Settings')} description={t('admin.health.settings_hint', 'Schedule, notifications, AI approval, and image optimization defaults.')} />
                <form onSubmit={(e) => { e.preventDefault(); form.put('/admin/health/settings'); }} className="space-y-4">
                    <Card className="space-y-3">
                        <Checkbox checked={!!form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} label={t('admin.health.enable', 'Enable Store Health')} />
                        <div>
                            <label className={ui.label}>{t('admin.health.scan_schedule', 'Scan schedule')}</label>
                            <Select value={form.data.scan_schedule} onChange={(e) => form.setData('scan_schedule', e.target.value)}>
                                <option value="manual">Manual</option>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </Select>
                        </div>
                        <Checkbox checked={!!form.data.notify_critical} onChange={(e) => form.setData('notify_critical', e.target.checked)} label={t('admin.health.notify_critical', 'Notify on critical issues')} />
                        <Checkbox checked={!!form.data.notify_score_drop} onChange={(e) => form.setData('notify_score_drop', e.target.checked)} label={t('admin.health.notify_drop', 'Notify when score drops')} />
                    </Card>
                    <Card className="space-y-3">
                        <h3 className={ui.sectionTitle}>{t('admin.health.ai_settings', 'AI settings')}</h3>
                        <Checkbox checked={!!form.data.ai_enabled} onChange={(e) => form.setData('ai_enabled', e.target.checked)} label={t('admin.health.ai_enable', 'Enable AI suggestions')} />
                        <Checkbox checked={!!form.data.ai_require_approval} onChange={(e) => form.setData('ai_require_approval', e.target.checked)} label={t('admin.health.ai_approval', 'Require approval before applying AI')} />
                        <div>
                            <label className={ui.label}>{t('admin.health.ai_tone', 'Default tone')}</label>
                            <Input value={form.data.ai_tone} onChange={(e) => form.setData('ai_tone', e.target.value)} />
                        </div>
                    </Card>
                    <Card className="space-y-3">
                        <h3 className={ui.sectionTitle}>{t('admin.health.image_settings', 'Image optimization')}</h3>
                        <Checkbox checked={!!form.data.images_auto_webp} onChange={(e) => form.setData('images_auto_webp', e.target.checked)} label={t('admin.health.auto_webp', 'Prefer WebP output')} />
                        <Checkbox checked={!!form.data.images_keep_original} onChange={(e) => form.setData('images_keep_original', e.target.checked)} label={t('admin.health.keep_original', 'Keep originals when optimizing')} />
                        <div>
                            <label className={ui.label}>{t('admin.health.max_edge', 'Max edge (px)')}</label>
                            <Input type="number" value={form.data.images_max_edge} onChange={(e) => form.setData('images_max_edge', e.target.value)} />
                        </div>
                    </Card>
                    <Button type="submit" icon="check" busy={form.processing}>{t('admin.common.save', 'Save')}</Button>
                </form>
            </HealthShell>
        </Layout>
    );
}
