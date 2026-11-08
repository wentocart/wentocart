import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    ColumnFilter,
    EmptyState,
    FilterReset,
    KpiCards,
    ListFooter,
    ListPanel,
    PageHeader,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    formatMoney,
    ui,
    t,
} from '../../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

const STATUS_TONES = {
    active: 'success',
    used: 'neutral',
    disabled: 'warning',
    cancelled: 'danger',
    expired: 'danger',
};

function statusLabel(status) {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function statusOptions() {
    return [
        { value: 'active', label: t('admin.gift_cards.status_active', 'Active') },
        { value: 'used', label: t('admin.gift_cards.status_used', 'Used') },
        { value: 'disabled', label: t('admin.gift_cards.status_disabled', 'Disabled') },
        { value: 'cancelled', label: t('admin.gift_cards.status_cancelled', 'Cancelled') },
        { value: 'expired', label: t('admin.gift_cards.status_expired', 'Expired') },
    ];
}

function balanceOptions() {
    return [
        { value: 'remaining', label: t('admin.gift_cards.balance_remaining', 'Balance remaining') },
        { value: 'empty', label: t('admin.gift_cards.balance_empty', 'No balance') },
    ];
}

export default function Index({ cards, counts = {}, filters = {} }) {
    const { auth } = usePage().props;
    const rows = cards.data || [];
    const currency = auth?.currency_code || 'USD';

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        balance: filters.balance || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/gift-cards', cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const kpis = [
        { key: 'all', label: t('admin.gift_cards.all', 'All cards'), value: counts.all ?? 0 },
        { key: 'active', label: statusLabel('active'), value: counts.active ?? 0, tone: 'success' },
        { key: 'used', label: statusLabel('used'), value: counts.used ?? 0 },
        { key: 'disabled', label: statusLabel('disabled'), value: counts.disabled ?? 0, tone: 'warning' },
        { key: 'expired', label: statusLabel('expired'), value: counts.expired ?? 0, tone: 'danger' },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.gift_cards.gift_cards', 'Gift cards')}
                total={cards.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/products', label: t('admin.gift_cards.catalog', 'Catalog') },
                    { label: t('admin.gift_cards.gift_cards', 'Gift cards') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" href="/admin/products/create?type=gift_card">
                            {t('admin.gift_cards.new_product', 'New gift card product')}
                        </Button>
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                footer={<ListFooter pagination={cards} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.gift_cards.code', 'Code')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="status" label={t('admin.gift_cards.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="initial_balance" label={t('admin.gift_cards.initial', 'Initial')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="current_balance" label={t('admin.gift_cards.balance', 'Balance')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.gift_cards.owner', 'Owner / recipient')}</Th>
                            <Th>{t('admin.gift_cards.order', 'Order')}</Th>
                            <SortHeader column="expires_at" label={t('admin.gift_cards.expires', 'Expires')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.gift_cards.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilterId} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="q"
                                    value={query.q}
                                    placeholder={t('admin.gift_cards.search_placeholder', 'Code, email, order')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={statusOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="balance"
                                    value={query.balance}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={balanceOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/gift-cards" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={9}>
                                {t('admin.gift_cards.none', 'No issued gift cards yet. Configure a Gift Card product under Catalog → Products, then issue cards from the product Gift Card tab (or wait for customer purchases).')}
                            </EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id}>
                                <Td col="id">{row.id}</Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <a href={`/admin/gift-cards/${row.id}`} className={ui.cellStrong}>{row.code}</a>
                                        <div className={ui.cellSub}>{row.product?.name || '—'}</div>
                                    </div>
                                </Td>
                                <Td col="status">
                                    <Badge tone={row.is_expired && row.status !== 'expired' ? 'danger' : (STATUS_TONES[row.status] || 'neutral')}>
                                        {row.is_expired && row.status !== 'expired' ? statusLabel('expired') : statusLabel(row.status)}
                                    </Badge>
                                </Td>
                                <Td>{formatMoney(row.initial_balance, currency)}</Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <div className={ui.cellStrong}>{formatMoney(row.current_balance, currency)}</div>
                                        {row.used_amount > 0 ? (
                                            <div className={ui.cellSub}>
                                                {t('admin.gift_cards.used_of', 'Used {amount}', { amount: formatMoney(row.used_amount, currency) })}
                                            </div>
                                        ) : null}
                                    </div>
                                </Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <div className="text-sm text-neutral-700">{row.customer?.name || row.recipient_name || '—'}</div>
                                        <div className={ui.cellSub}>{row.customer?.email || row.recipient_email || '—'}</div>
                                    </div>
                                </Td>
                                <Td>
                                    {row.order ? (
                                        <a href={`/admin/orders/${row.order.id}`} className="text-sm text-primary-600">
                                            {row.order.number}
                                        </a>
                                    ) : '—'}
                                </Td>
                                <Td>
                                    <span className="text-sm text-neutral-700">{row.expires_at || t('admin.gift_cards.never', 'Never')}</span>
                                </Td>
                                <Td actions>
                                    <Button variant="secondary" href={`/admin/gift-cards/${row.id}`}>
                                        {t('admin.common.view', 'View')}
                                    </Button>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
