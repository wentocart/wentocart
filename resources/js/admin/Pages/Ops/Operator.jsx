import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Card,
    Field,
    Input,
    PageHeader,
    Select,
    Textarea,
    ui,
    t,
} from '../../ui';

export default function Operator({ abilities = [] }) {
    const form = useForm({
        ability: abilities[0]?.code || 'catalog.product.bulk_price',
        input: '{\n  "ids": [1],\n  "percent": 10\n}',
        reason: '',
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.operator.title', 'AI operator')}
                description={t('admin.operator.hint', 'Preview a ChangeSet, then approve. Writes go through the same abilities as the assistant — never raw SQL.')}
            />
            <Card className="max-w-2xl space-y-4">
                <Field label={t('admin.operator.ability', 'Ability')}>
                    <Select wide value={form.data.ability} onChange={(e) => form.setData('ability', e.target.value)}>
                        {abilities.map((row) => (
                            <option key={row.code} value={row.code}>{row.label}</option>
                        ))}
                    </Select>
                </Field>
                <Field label={t('admin.operator.input', 'Input (JSON)')} hint={t('admin.operator.bulk_hint', 'Example: {"ids":[1,2], "percent":10} for bulk price.')}>
                    <Textarea rows={10} value={form.data.input} onChange={(e) => form.setData('input', e.target.value)} />
                </Field>
                <Field label={t('admin.operator.reason', 'Reason (optional)')}>
                    <Input wide value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                </Field>
                <div className="flex gap-2">
                    <Button type="button" variant="secondary" busy={form.processing} onClick={() => form.post('/admin/operator/preview')}>
                        {t('admin.operator.preview', 'Preview')}
                    </Button>
                    <Button type="button" busy={form.processing} onClick={() => form.post('/admin/operator/execute')}>
                        {t('admin.operator.execute', 'Approve & execute')}
                    </Button>
                </div>
                <p className={ui.muted}>{t('admin.operator.audit', 'Every run is stored in Activity and can be rolled back when reversible.')}</p>
            </Card>
        </Layout>
    );
}
