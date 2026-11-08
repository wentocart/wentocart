import { router, useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import PagePermissionPicker from '../PagePermissionPicker';
import StaffAccessFlow from '../StaffAccessFlow';
import {
    Badge,
    Button,
    Field,
    FormErrors,
    Input,
    PageHeader,
    ui,
    t,
} from '../../../ui';

export default function Form({ role, groups = [] }) {
    const form = useForm({
        name: role?.name || '',
        permissions: role?.permissions || [],
    });

    const totalPages = groups.reduce((sum, g) => sum + g.pages.length, 0);

    const submit = (e) => {
        e.preventDefault();
        if (role) {
            form.put(`/admin/staff/roles/${role.id}`);
        } else {
            form.post('/admin/staff/roles');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={role ? t('admin.roles.edit_role', 'Edit permission') : t('admin.roles.add_role', 'Add permission')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/staff/roles', label: t('admin.roles.roles', 'Permission') },
                    { label: role ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    role && !role.is_system ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.roles.delete_this_role', 'Delete this permission?'))) {
                                    router.delete(`/admin/staff/roles/${role.id}`);
                                }
                            }}
                        >
                            {t('admin.common.delete', 'Delete')}
                        </Button>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className="space-y-4">
                <StaffAccessFlow highlight="role" />

                <div className={ui.formLayoutWide}>
                    <div className={ui.formMain}>
                        <FormErrors errors={form.errors} />

                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <div>
                                    <p className={ui.sectionKicker}>{t('admin.roles.role', 'Permission')}</p>
                                    <h2 className={ui.sectionTitle}>{t('admin.roles.name_and_pages', 'Name and page access')}</h2>
                                </div>
                                <Badge tone="primary">
                                    {form.data.permissions.length} / {totalPages}
                                </Badge>
                            </div>
                            <Field label={t('admin.roles.name', 'Name')} error={form.errors.name}>
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.roles.catalog_manager', 'Catalog manager')}
                                />
                            </Field>
                        </section>

                        <section className={ui.sectionCard}>
                            <div className="mb-4">
                                <p className={ui.sectionKicker}>{t('admin.roles.pages_this_role_can_open', 'Pages this permission can open')}</p>
                                <p className={ui.sectionLead}>
                                    {t(
                                        'admin.roles.tick_only_the_admin_screens_this_job_needs_attac',
                                        'Tick only the admin screens this job needs. Attach the permission to a profile, then assign that profile to employees.',
                                    )}
                                </p>
                            </div>
                            <PagePermissionPicker
                                groups={groups}
                                value={form.data.permissions}
                                onChange={(permissions) => form.setData('permissions', permissions)}
                                error={form.errors.permissions}
                            />
                        </section>
                    </div>

                    <div className={ui.formSide}>
                        <section className={ui.sectionCard}>
                            <p className={ui.muted}>
                                {t(
                                    'admin.roles.super_admin_is_not_listed_here_that_role_always_',
                                    'Super admin is not listed here. That access always includes every page and is assigned on the employee, not through a profile.',
                                )}
                            </p>
                        </section>
                        <section className={ui.sectionCard}>
                            <div className={ui.actionList}>
                                <Button icon="check" busy={form.processing} type="submit">
                                    {role ? t('admin.common.save', 'Save changes') : t('admin.roles.create_role', 'Create permission')}
                                </Button>
                                <Button type="button" variant="secondary" href="/admin/staff/roles">
                                    {t('admin.common.cancel', 'Cancel')}
                                </Button>
                            </div>
                        </section>
                    </div>
                </div>
            </form>
        </Layout>
    );
}
