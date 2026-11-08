import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../Layout';
import {
    ColumnFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    PageHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../ui';

export default function Audits({ rows, filters = {}, actions = [], entity_types = [] }) {
    const records = rows?.data || [];
    const query = useMemo(() => ({
        search: filters.search || '',
        action: filters.action || '',
        entity_type: filters.entity_type || '',
        seller_id: filters.seller_id || '',
    }), [filters]);

    const applyFilters = useCallback((patch = {}) => {
        router.get('/admin/multivendor/audits', { ...query, ...patch }, {
            preserveState: true,
            replace: true,
        });
    }, [query]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.multivendor.audits', 'Marketplace audit trail')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/dashboard', label: t('admin.nav.multivendor', 'Multivendor Management') },
                    { label: t('admin.multivendor.audits', 'Audit trail') },
                ]}
            />
            <ListPanel
                footer={<ListFooter pagination={rows} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.common.when', 'When')}</Th>
                            <Th>{t('admin.multivendor.audit_action', 'Action')}</Th>
                            <Th>{t('admin.multivendor.audit_entity', 'Entity')}</Th>
                            <Th>{t('admin.multivendor.audit_actor', 'Actor')}</Th>
                            <Th>{t('admin.multivendor.audit_seller', 'Seller')}</Th>
                            <Th>{t('admin.common.reason', 'Reason')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="action"
                                    value={query.action}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={actions.map((action) => ({ value: action, label: action }))}
                                    onApply={(name, value) => applyFilters({ [name]: value })}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="entity_type"
                                    value={query.entity_type}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={entity_types.map((type) => ({ value: type, label: type }))}
                                    onApply={(name, value) => applyFilters({ [name]: value })}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="search"
                                    value={query.search}
                                    placeholder={t('admin.multivendor.audit_search', 'Action, reference, reason')}
                                    onApply={(name, value) => applyFilters({ [name]: value })}
                                />
                            </Th>
                            <Th actions className={ui.thFilterActions}>
                                <FilterReset
                                    query={query}
                                    onReset={() => applyFilters({ search: '', action: '', entity_type: '', seller_id: '' })}
                                />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {records.length === 0 ? (
                            <EmptyState colSpan={6}>{t('admin.multivendor.audit_empty', 'No audit records yet.')}</EmptyState>
                        ) : records.map((row) => (
                            <Tr key={row.id}>
                                <Td>{row.created_at || '—'}</Td>
                                <Td>{row.action}</Td>
                                <Td>{row.entity_type} #{row.entity_id}</Td>
                                <Td>{row.actor_type}{row.actor_id ? ` #${row.actor_id}` : ''}</Td>
                                <Td>{row.seller_id ? `#${row.seller_id}` : '—'}</Td>
                                <Td>{row.reason || row.reference || '—'}</Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
