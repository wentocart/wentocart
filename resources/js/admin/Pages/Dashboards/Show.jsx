import { lazy, Suspense } from 'react';
import { router, usePage } from '@inertiajs/react';
import Layout from '../Layout';
import { Button, PageHeader, Select, ui, t } from '../../ui';

const WidgetBoard = lazy(() => import('./WidgetBoard').then((mod) => {
    if (!mod?.default) {
        throw new Error('Dashboard board failed to load');
    }
    return mod;
}));

export default function Show({ dashboard, available = [], canManage = false }) {
    const currency = usePage().props.shop?.currency || 'USD';
    const widgets = dashboard?.widgets || [];

    return (
        <Layout>
            <PageHeader
                title={dashboard?.name || t('admin.nav.dashboard', 'Dashboard')}
                crumbs={[{ href: '/admin/dashboards', label: t('admin.dashboards.dashboards', 'Dashboards') }, { label: dashboard?.name || t('admin.nav.dashboard', 'Dashboard') }]}
                actions={
                    <div className={ui.toolbar}>
                        {available.length > 1 ? (
                            <Select
                                value={dashboard?.id || ''}
                                onChange={(e) => {
                                    const id = e.target.value;
                                    router.get(id ? `/admin/dashboards/${id}` : '/admin', {}, { preserveState: false });
                                }}
                                aria-label={t('admin.dashboards.switch_dashboard', 'Switch dashboard')}
                            >
                                {available.map((board) => (
                                    <option key={board.id} value={board.id}>{board.name}</option>
                                ))}
                            </Select>
                        ) : null}
                        {canManage ? (
                            <>
                                {dashboard ? (
                                    <Button variant="secondary" href={`/admin/dashboards/${dashboard.id}/edit`}>
                                        {t('admin.dashboards.edit_board', 'Edit board')}
                                    </Button>
                                ) : null}
                                <Button icon="plus" href="/admin/dashboards/create">
                                    {t('admin.dashboards.new_dashboard', 'New dashboard')}
                                </Button>
                            </>
                        ) : (
                            <Button variant="secondary" href="/admin/dashboards">
                                {t('admin.dashboards.all_dashboards', 'All dashboards')}
                            </Button>
                        )}
                    </div>
                }
            />

            {dashboard?.description ? (
                <p className={`${ui.muted} mb-5`}>{dashboard.description}</p>
            ) : null}

            {!dashboard ? (
                <section className={ui.chartCard}>
                    <p className={ui.muted}>
                        {t('admin.dashboards.no_dashboard_is_assigned_to_you_yet_a_super_admi', 'No dashboard is assigned to you yet. A super admin can create a board and add you as a viewer.')}
                    </p>
                </section>
            ) : widgets.length === 0 ? (
                <section className={ui.chartCard}>
                    <p className={ui.muted}>{t('admin.dashboards.this_dashboard_has_no_widgets_yet', 'This dashboard has no widgets yet.')}</p>
                    {canManage && dashboard ? (
                        <div className="mt-3">
                            <Button href={`/admin/dashboards/${dashboard.id}/edit`}>{t('admin.dashboards.add_widgets', 'Add widgets')}</Button>
                        </div>
                    ) : null}
                </section>
            ) : (
                <Suspense fallback={<p className={ui.muted}>{t('admin.common.loading', 'Loading…')}</p>}>
                    <WidgetBoard
                        dashboardId={dashboard.id}
                        widgets={widgets}
                        currency={currency}
                        editable={canManage}
                    />
                </Suspense>
            )}
        </Layout>
    );
}
