import { Link, router } from '@inertiajs/react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    EmptyState,
    ListPanel,
    PageHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Alert,
    t,
} from '../../../ui';

export default function Index({ automations = [], halted = false }) {
    return (
        <Layout>
            <PageHeader
                title={t('admin.automations.title', 'Automations')}
                description={t('admin.automations.hint', 'When an event fires, optionally check a condition, then run an ability. Runs are logged and reversible when the ability allows it.')}
                actions={(
                    <>
                        <Button
                            variant="secondary"
                            type="button"
                            onClick={() => router.post('/admin/automations/halt', { halted: !halted })}
                        >
                            {halted
                                ? t('admin.automations.resume', 'Resume all')
                                : t('admin.automations.halt', 'Halt all')}
                        </Button>
                        <Button href="/admin/automations/create">{t('admin.automations.add', 'Add automation')}</Button>
                    </>
                )}
            />
            {halted ? (
                <Alert type="warning" className="mb-4">
                    {t('admin.automations.halted', 'Global halt is on. No automation will run until you resume.')}
                </Alert>
            ) : null}
            <ListPanel>
                {automations.length === 0 ? (
                    <EmptyState title={t('admin.automations.empty', 'No automations yet')} />
                ) : (
                    <Table>
                        <THead>
                            <Th>{t('admin.automations.name', 'Name')}</Th>
                            <Th>{t('admin.automations.when', 'When')}</Th>
                            <Th>{t('admin.automations.then', 'Then')}</Th>
                            <Th>{t('admin.automations.runs', 'Runs')}</Th>
                            <Th>{t('admin.common.status', 'Status')}</Th>
                            <Th>{t('admin.common.actions', 'Actions')}</Th>
                        </THead>
                        <tbody>
                            {automations.map((row) => (
                                <Tr key={row.id}>
                                    <Td><Link href={`/admin/automations/${row.id}/edit`}>{row.name}</Link></Td>
                                    <Td>{row.event}</Td>
                                    <Td>{row.ability}</Td>
                                    <Td>{row.runs_count}</Td>
                                    <Td>
                                        <Badge tone={row.is_enabled ? 'success' : 'neutral'}>
                                            {row.is_enabled ? t('admin.common.on', 'On') : t('admin.common.off', 'Off')}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <Button variant="secondary" type="button" onClick={() => router.post(`/admin/automations/${row.id}/toggle`)}>
                                            {row.is_enabled ? t('admin.common.disable', 'Disable') : t('admin.common.enable', 'Enable')}
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </ListPanel>
        </Layout>
    );
}
