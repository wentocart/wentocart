import { router, useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import PagePermissionPicker from '../PagePermissionPicker';
import StaffAccessFlow from '../StaffAccessFlow';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Textarea,
    ui,
    t,
} from '../../../ui';

export default function Form({ profile, groups = [] }) {
    const form = useForm({
        name: profile?.name || '',
        description: profile?.description || '',
        is_enabled: profile?.is_enabled ?? true,
        permissions: profile?.permissions || [],
    });

    const totalPages = groups.reduce((sum, g) => sum + g.pages.length, 0);

    const submit = (e) => {
        e.preventDefault();
        form.clearErrors();
        const errors = {};
        if (!String(form.data.name || '').trim()) {
            errors.name = t('admin.profiles.name_required', 'The name field is required.');
        }
        if (!Array.isArray(form.data.permissions) || form.data.permissions.length < 1) {
            errors.permissions = t('admin.profiles.permissions_required', 'The permissions field is required.');
        }
        if (Object.keys(errors).length) {
            form.setError(errors);
            return;
        }
        if (profile) {
            form.put(`/admin/staff/profiles/${profile.id}`);
        } else {
            form.post('/admin/staff/profiles');
        }
    };

    const inlineErrors = ['name', 'description', 'permissions'];

    return (
        <Layout>
            <PageHeader
                title={profile ? t('admin.profiles.edit_profile', 'Edit profile') : t('admin.profiles.add_profile', 'Add profile')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/staff/profiles', label: t('admin.profiles.profiles', 'Profiles') },
                    { label: profile ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    profile && !profile.admins_count && !profile.teams_count ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.profiles.delete_this_profile', 'Delete this profile?'))) {
                                    router.delete(`/admin/staff/profiles/${profile.id}`);
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
                <StaffAccessFlow highlight="pages" />

                <div className={ui.formLayoutWide}>
                    <div className={ui.formMain}>
                        <FormErrors errors={form.errors} except={inlineErrors} />

                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <div>
                                    <p className={ui.sectionKicker}>{t('admin.profiles.profile', 'Profile')}</p>
                                    <h2 className={ui.sectionTitle}>{t('admin.profiles.job_template', 'Job template')}</h2>
                                </div>
                            </div>
                            <div className="grid gap-3">
                                <Field label={t('admin.profiles.name', 'Name')} error={form.errors.name} required>
                                    <Input
                                        wide
                                        invalid={!!form.errors.name}
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder={t('admin.profiles.catalog_editor', 'Catalog editor')}
                                    />
                                </Field>
                                <Field label={t('admin.profiles.description', 'Description')} error={form.errors.description} optional>
                                    <Textarea
                                        rows={3}
                                        invalid={!!form.errors.description}
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder={t('admin.profiles.what_this_job_is_allowed_to_do', 'What this job is allowed to do')}
                                    />
                                </Field>
                            </div>
                        </section>

                        <section className={ui.sectionCard}>
                            <div className="mb-4">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className={ui.sectionKicker}>{t('admin.profiles.admin_pages', 'Admin pages')} <span className={ui.req}>*</span></p>
                                        <p className={ui.sectionLead}>
                                            {t(
                                                'admin.profiles.pick_admin_pages',
                                                'Tick the admin screens teams with this profile may open. Employees inherit access from their team’s profile.',
                                            )}
                                        </p>
                                    </div>
                                    <Badge tone="primary">
                                        {form.data.permissions.length} / {totalPages}
                                    </Badge>
                                </div>
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
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.profiles.status', 'Status')}</h2>
                            </div>
                            <Checkbox
                                label={t('admin.common.active', 'Active')}
                                checked={!!form.data.is_enabled}
                                onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            />
                            {profile?.admins_count ? (
                                <p className={`${ui.metaK} mt-2`}>
                                    {profile.admins_count} employee{profile.admins_count === 1 ? '' : 's'} use this profile.
                                </p>
                            ) : null}
                            {profile?.teams_count ? (
                                <p className={`${ui.metaK} mt-2`}>
                                    {profile.teams_count} team{profile.teams_count === 1 ? '' : 's'} use this profile.
                                </p>
                            ) : null}
                        </section>

                        <section className={ui.sectionCard}>
                            <div className={ui.actionList}>
                                <Button icon="check" busy={form.processing} type="submit">
                                    {profile ? t('admin.common.save', 'Save changes') : t('admin.profiles.create_profile', 'Create profile')}
                                </Button>
                                <Button type="button" variant="secondary" href="/admin/staff/profiles">
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
