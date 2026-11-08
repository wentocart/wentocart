import { Link, useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import {
    Button,
    Card,
    Field,
    Input,
    PageHeader,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    Toggle,
    ui,
    t,
} from '../../../ui';

export default function Form({ automation = null, abilities = [] }) {
    const form = useForm({
        name: automation?.name || '',
        is_enabled: automation?.is_enabled !== false,
        event: automation?.event || 'product.updated',
        condition_path: automation?.condition_path || '',
        condition_equals: automation?.condition_equals || '',
        ability: automation?.ability || abilities[0]?.code || 'settings.put',
        action_input: JSON.stringify(automation?.action_input || { key: 'ops.last_automation', value: '1' }, null, 2),
    });
    const save = (e) => {
        e.preventDefault();
        if (automation?.id) {
            form.put(`/admin/automations/${automation.id}`);
        } else {
            form.post('/admin/automations');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={automation?.id ? t('admin.automations.edit', 'Edit automation') : t('admin.automations.add', 'Add automation')}
                crumbs={[
                    { href: '/admin/automations', label: t('admin.automations.title', 'Automations') },
                    { label: automation?.name || t('admin.common.add', 'Add') },
                ]}
            />
            <form onSubmit={save} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                <Card className="space-y-4">
                    <Field label={t('admin.automations.name', 'Name')} error={form.errors.name}>
                        <Input wide value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <Field label={t('admin.automations.when', 'When (event)')} hint={t('admin.automations.when_hint', 'Examples: product.updated, order.status.updated, settings.updated')}>
                        <Input wide value={form.data.event} onChange={(e) => form.setData('event', e.target.value)} />
                    </Field>
                    <Field label={t('admin.automations.if', 'If (optional)')} hint={t('admin.automations.if_hint', 'Payload path and expected value. Leave blank to always run.')}>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Input placeholder="ability" value={form.data.condition_path} onChange={(e) => form.setData('condition_path', e.target.value)} />
                            <Input placeholder="catalog.product.update" value={form.data.condition_equals} onChange={(e) => form.setData('condition_equals', e.target.value)} />
                        </div>
                    </Field>
                    <Field label={t('admin.automations.then', 'Then (ability)')}>
                        <Select wide value={form.data.ability} onChange={(e) => form.setData('ability', e.target.value)}>
                            {abilities.map((row) => (
                                <option key={row.code} value={row.code}>{row.label}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label={t('admin.automations.input', 'Ability input (JSON)')}>
                        <Textarea rows={8} value={form.data.action_input} onChange={(e) => form.setData('action_input', e.target.value)} />
                    </Field>
                    <Toggle
                        label={t('admin.common.enabled', 'Enabled')}
                        value={form.data.is_enabled}
                        onChange={(on) => form.setData('is_enabled', on)}
                    />
                    <Button type="submit" busy={form.processing}>{t('admin.common.save', 'Save')}</Button>
                </Card>
                <div className="space-y-3">
                    <Card>
                        <p className={ui.muted}>{t('admin.automations.runs', 'Recent runs')}</p>
                        {(automation?.runs || []).length === 0 ? (
                            <p className="mt-2 text-sm text-neutral-500">{t('admin.automations.no_runs', 'No runs yet.')}</p>
                        ) : (
                            <Table>
                                <THead>
                                    <Th>{t('admin.common.status', 'Status')}</Th>
                                    <Th>{t('admin.activity.when', 'When')}</Th>
                                </THead>
                                <tbody>
                                    {automation.runs.map((run) => (
                                        <Tr key={run.id}>
                                            <Td>
                                                {run.operation_id ? (
                                                    <Link href={`/admin/activity/${run.operation_id}`}>{run.status}</Link>
                                                ) : run.status}
                                                <div className={ui.muted}>{run.message}</div>
                                            </Td>
                                            <Td>{run.created_at}</Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </Card>
                </div>
            </form>
        </Layout>
    );
}
