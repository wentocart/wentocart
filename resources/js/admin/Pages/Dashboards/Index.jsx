import { lazy, Suspense } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    FilterBar,
    Icon,
    PageHeader,
    Pagination,
    KpiCards,
    money,
    ui,
    cx,
    t,
} from '../../ui';

const WidgetCard = lazy(() => import('./ChartWidget').then((mod) => {
    if (!mod?.default) {
        throw new Error('Chart widget failed to load');
    }
    return mod;
}));

const BOARD_ICON = {
    'store-overview': 'star',
    sales: 'bag',
    catalog: 'package',
    customers: 'users',
    operations: 'settings',
};

const BOARD_TONE = {
    'store-overview': 'bg-[#FFF7E6] text-[#F59E0B]',
    catalog: 'bg-[#EAFBF3] text-[#10B981]',
    sales: 'bg-[#FFF0F2] text-[#F43F5E]',
    customers: 'bg-[#EFF6FF] text-[#3B82F6]',
    operations: 'bg-[#F3EEFF] text-[#6D28D9]',
};

const BOARD_BLURB_KEYS = {
    'store-overview': ['admin.dashboards.blurb_store_overview', 'General store performance at a glance'],
    catalog: ['admin.dashboards.blurb_catalog', 'Product, category and stock insights'],
    sales: ['admin.dashboards.blurb_sales', 'Sales performance and trends'],
    customers: ['admin.dashboards.blurb_customers', 'Customer growth and segmentation'],
    operations: ['admin.dashboards.blurb_operations', 'Orders, shipping and fulfillment'],
};

function listUrl(status = 'all', q = '') {
    const params = new URLSearchParams();
    if (status && status !== 'all') {
        params.set('status', status);
    }
    if (q) {
        params.set('q', q);
    }
    const query = params.toString();

    return query ? `/admin/dashboards?${query}` : '/admin/dashboards';
}

function boardHighlights(board, currency) {
    return (board.widgets || [])
        .filter((widget) => widget.chart_type === 'kpi' || widget.chart_type === 'gauge')
        .slice(0, 4)
        .map((widget) => ({
            label: widget.title,
            value: widget.data?.money
                ? money(widget.data.value, currency)
                : Number(widget.data?.value ?? 0).toLocaleString(),
        }));
}

function boardChart(board) {
    const widgets = board.widgets || [];
    const charts = widgets.filter((widget) => widget.chart_type && widget.chart_type !== 'kpi' && widget.chart_type !== 'gauge');

    return charts[0] || widgets[0] || null;
}

function MiniDashboardCard({ board, currency, canManage }) {
    const chart = boardChart(board);
    const stats = boardHighlights(board, currency);
    const iconTone = BOARD_TONE[board.slug] || BOARD_TONE.operations;
    const blurbRow = BOARD_BLURB_KEYS[board.slug];
    const blurb = blurbRow ? t(blurbRow[0], blurbRow[1]) : (board.description || '');
    const visibility = board.visibility === 'assigned'
        ? t('admin.dashboards.restricted_to_named_admins', 'Restricted to named admins')
        : t('admin.dashboards.visible_to_all_admins', 'Visible to all admins');

    return (
        <article className={ui.dashBoardCard}>
            <div className={ui.dashBoardHead}>
                <div className="flex min-w-0 items-start gap-3">
                    <span className={cx(ui.dashBoardIcon, iconTone)}>
                        <Icon name={BOARD_ICON[board.slug] || 'bar-chart'} className={ui.iconLg} />
                    </span>
                    <div className="min-w-0">
                        <h3 className={ui.dashBoardTitle}>{board.name}</h3>
                        {blurb ? <p className={ui.dashBoardBlurb}>{blurb}</p> : null}
                    </div>
                </div>
                <div className="flex shrink-0 items-start gap-1">
                    {board.updated_at ? (
                        <p className={ui.dashBoardUpdated}>
                            {t('admin.dashboards.updated', 'Updated')} {board.updated_at}
                        </p>
                    ) : null}
                    {canManage && board.actions?.edit ? (
                        <Link
                            href={`/admin/dashboards/${board.id}/edit`}
                            className={ui.menuBtn}
                            title={t('admin.common.edit', 'Edit')}
                        >
                            <Icon name="more" />
                        </Link>
                    ) : null}
                </div>
            </div>

            <div className="pointer-events-none min-h-0 flex-1 px-3">
                {chart ? (
                    <Suspense fallback={<div className={cx(ui.dashBoardChart, 'grid place-items-center text-xs text-[#697386]')}>{t('admin.common.loading', 'Loading…')}</div>}>
                        <WidgetCard widget={{ ...chart, col_span: 12 }} currency={currency} compact bare />
                    </Suspense>
                ) : (
                    <div className={cx(ui.dashBoardChart, 'grid place-items-center text-xs text-[#697386]')}>
                        {t('admin.dashboards.this_dashboard_has_no_widgets_yet', 'This dashboard has no widgets yet.')}
                    </div>
                )}
            </div>

            {stats.length ? (
                <div className={ui.dashBoardMetrics}>
                    {stats.map((stat) => (
                        <div key={stat.label} className={ui.dashBoardMetric}>
                            <div className={ui.dashBoardMetricValue}>{stat.value}</div>
                            <div className={ui.dashBoardMetricLabel}>{stat.label}</div>
                        </div>
                    ))}
                </div>
            ) : null}

            <div className={ui.dashBoardFooter}>
                <p className="flex items-center gap-1.5 text-xs text-[#697386]">
                    <Icon name="lock" />
                    {visibility}
                </p>
                <Link href={`/admin/dashboards/${board.id}`} className={ui.dashBoardOpen}>
                    {t('admin.dashboards.open', 'Open')}
                </Link>
            </div>
        </article>
    );
}

export default function Index({ dashboards, counts = {}, canManage = false, filters = {} }) {
    const currency = usePage().props.shop?.currency || 'USD';
    const status = filters.status || 'all';
    const q = filters.q || '';
    const boards = dashboards?.data || [];
    const kpis = [
        { key: 'total', label: t('admin.dashboards.all_boards', 'All boards'), value: (counts.all ?? 0).toLocaleString(), trend: 0 },
        { key: 'active', label: t('admin.common.active', 'Active'), value: (counts.active ?? 0).toLocaleString(), trend: 0 },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), value: (counts.inactive ?? 0).toLocaleString(), trend: 0 },
        { key: 'assigned', label: t('admin.dashboards.assigned', 'Assigned'), value: (counts.assigned ?? 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.dashboards.dashboards', 'Dashboards')}
                description={t('admin.dashboards.landing_hint', 'Create and manage custom dashboards to monitor your store performance.')}
                actions={
                    canManage ? (
                        <Button icon="plus" href="/admin/dashboards/create">
                            {t('admin.dashboards.create_dashboard', 'Create dashboard')}
                        </Button>
                    ) : null
                }
            />

            <FilterBar
                search={q}
                searchPlaceholder={t('admin.dashboards.search_placeholder', 'Search dashboards…')}
                onSearchSubmit={(value) => router.get(listUrl(status, value), {}, { preserveState: true, replace: true })}
            />

            <KpiCards items={kpis} />

            {boards.length ? (
                <div className={ui.dashBoardGrid}>
                    {boards.map((board) => (
                        <MiniDashboardCard key={board.id} board={board} currency={currency} canManage={canManage} />
                    ))}
                </div>
            ) : (
                <p className={`${ui.muted} mt-4`}>{t('admin.dashboards.no_dashboards_yet', 'No dashboards yet.')}</p>
            )}

            {dashboards ? <Pagination paginator={dashboards} query={{ status, q }} /> : null}
        </Layout>
    );
}
