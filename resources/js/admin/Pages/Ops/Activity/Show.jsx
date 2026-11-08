import { Link, router } from '@inertiajs/react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Card,
    PageHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../../ui';

export default function Show({ operation }) {
    return (
        <Layout>
            <PageHeader
                title={operation.summary || t('admin.activity.change', 'Change')}
                crumbs={[
                    { href: '/admin/activity', label: t('admin.activity.title', 'Activity') },
                    { label: `#${operation.id}` },
                ]}
                actions={operation.can_rollback ? (
                    <Button type="button" onClick={() => router.post(`/admin/activity/${operation.id}/rollback`)}>
                        {t('admin.activity.rollback', 'Roll back')}
                    </Button>
                ) : null}
            />
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <p className={ui.muted}>{t('admin.activity.source', 'Source')}</p>
                    <p>{operation.source}</p>
                </Card>
                <Card>
                    <p className={ui.muted}>{t('admin.activity.status', 'Status')}</p>
                    <Badge>{operation.status}</Badge>
                </Card>
                <Card>
                    <p className={ui.muted}>{t('admin.activity.ability', 'Ability')}</p>
                    <p>{operation.ability}</p>
                </Card>
            </div>
            {operation.reason ? <p className="mt-3 text-sm text-neutral-600">{operation.reason}</p> : null}
            <div className="mt-4">
                <Table>
                    <THead>
                        <Th>{t('admin.activity.record', 'Record')}</Th>
                        <Th>{t('admin.activity.action', 'Action')}</Th>
                        <Th>{t('admin.activity.before', 'Before')}</Th>
                        <Th>{t('admin.activity.after', 'After')}</Th>
                    </THead>
                    <tbody>
                        {(operation.items || []).map((item) => (
                            <Tr key={item.id}>
                                <Td>
                                    {item.entity_type} {item.entity_id ? `#${item.entity_id}` : ''}
                                    <div className={ui.muted}>{item.label}</div>
                                </Td>
                                <Td>{item.action}{item.rolled_back ? ` · ${t('admin.activity.undone', 'undone')}` : ''}</Td>
                                <Td><pre className="whitespace-pre-wrap text-xs">{JSON.stringify(item.before || {}, null, 2)}</pre></Td>
                                <Td><pre className="whitespace-pre-wrap text-xs">{JSON.stringify(item.after || {}, null, 2)}</pre></Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </div>
            {operation.parent_id ? (
                <p className="mt-3">
                    <Link href={`/admin/activity/${operation.parent_id}`}>{t('admin.activity.view_original', 'View original change')}</Link>
                </p>
            ) : null}
        </Layout>
    );
}
