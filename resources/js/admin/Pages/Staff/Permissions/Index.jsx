import { useMemo, useState } from 'react';
import Layout from '../../Layout';
import PagePermissionPicker from '../PagePermissionPicker';
import StaffAccessFlow from '../StaffAccessFlow';
import {
    Button,
    Input,
    PageHeader,
    KpiCards,
    ui,
    t,
} from '../../../ui';

export default function Index({ groups = [], stats = {} }) {
    const [filter, setFilter] = useState('');

    const filteredGroups = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return groups;

        return groups
            .map((group) => ({
                ...group,
                pages: group.pages.filter((page) => {
                    const hay = `${page.label} ${page.slug} ${(page.roles || []).join(' ')}`.toLowerCase();
                    return hay.includes(q);
                }),
            }))
            .filter((group) => group.pages.length > 0);
    }, [groups, filter]);

    const kpis = [
        { key: 'pages', label: t('admin.permissions.admin_pages', 'Admin pages'), value: (stats.pages ?? 0).toLocaleString(), trend: 0 },
        { key: 'groups', label: t('admin.permissions.areas', 'Areas'), value: (stats.groups ?? 0).toLocaleString(), trend: 0 },
        { key: 'roles', label: t('admin.roles.roles', 'Permission'), value: (stats.roles ?? 0).toLocaleString(), trend: 0 },
        { key: 'unused', label: t('admin.permissions.unassigned', 'Unassigned'), value: (stats.unused ?? 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.permissions.permissions', 'Permission map')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/staff/employees', label: t('admin.permissions.staff', 'Staff') },
                    { label: t('admin.permissions.permissions', 'Permission map') },
                ]}
                actions={(
                    <Button type="button" variant="secondary" href="/admin/staff/roles">
                        {t('admin.permissions.manage_roles', 'Manage permissions')}
                    </Button>
                )}
            />

            <p className={`${ui.muted} -mt-3 mb-5 max-w-3xl`}>
                {t(
                    'admin.permissions.reference_map_lead',
                    'Read-only map of every admin page and which permissions include it. To change access, edit a permission or profile — you never assign pages directly to an employee.',
                )}
            </p>

            <div className="mb-4">
                <KpiCards items={kpis} />
            </div>

            <StaffAccessFlow highlight="permission" />

            <section className={`${ui.sectionCard} mt-4`}>
                <div className={ui.staffMainHead}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-neutral-900">
                                {t('admin.permissions.page_access_map', 'Page access map')}
                            </h2>
                            <p className="text-sm text-neutral-500 mt-0.5">
                                {t('admin.permissions.browse_by_area', 'Browse by admin area. Badges show permissions that grant each page.')}
                            </p>
                        </div>
                        <div className="w-full sm:w-72">
                            <Input
                                wide
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                                placeholder={t('admin.staff.picker.search_pages', 'Search pages…')}
                            />
                        </div>
                    </div>
                </div>
                <div className="p-5 pt-0">
                    {filteredGroups.length === 0 ? (
                        <p className={ui.muted}>{t('admin.staff.picker.no_pages_match', 'No pages match your search.')}</p>
                    ) : (
                        <PagePermissionPicker groups={filteredGroups} value={[]} readOnly />
                    )}
                </div>
            </section>

            <section className={`${ui.sectionCard} mt-4`}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-neutral-900">
                            {t('admin.permissions.where_to_edit', 'Where to edit access')}
                        </h2>
                        <p className={`${ui.muted} mt-1 max-w-2xl`}>
                            {t(
                                'admin.permissions.where_to_edit_hint',
                                'Permissions choose pages. Profiles bundle permissions. Employees get one profile. Super admin skips all of this.',
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="secondary" href="/admin/staff/roles">
                            {t('admin.roles.roles', 'Permission')}
                        </Button>
                        <Button type="button" variant="secondary" href="/admin/staff/profiles">
                            {t('admin.profiles.profiles', 'Profiles')}
                        </Button>
                        <Button type="button" href="/admin/staff/employees">
                            {t('admin.employees.employees', 'Employees')}
                        </Button>
                    </div>
                </div>
            </section>
        </Layout>
    );
}
