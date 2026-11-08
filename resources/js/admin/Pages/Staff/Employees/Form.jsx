import { router, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import Layout from '../../Layout';
import StaffAccessFlow from '../StaffAccessFlow';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    ui,
    t,
} from '../../../ui';

export default function Form({ employee, teams = [] }) {
    const form = useForm({
        name: employee?.name || '',
        email: employee?.email || '',
        password: '',
        job_title: employee?.job_title || '',
        phone: employee?.phone || '',
        team_id: employee?.team_id || '',
        is_enabled: employee?.is_enabled ?? true,
        is_super: employee?.is_super ?? false,
    });

    const lastSuper = Boolean(employee?.is_last_super);

    const selectedTeam = useMemo(
        () => teams.find((team) => String(team.id) === String(form.data.team_id)),
        [teams, form.data.team_id],
    );

    const submit = (e) => {
        e.preventDefault();
        form.clearErrors();
        const errors = {};
        if (!String(form.data.name || '').trim()) {
            errors.name = t('admin.employees.name_required', 'The name field is required.');
        }
        if (!String(form.data.email || '').trim()) {
            errors.email = t('admin.employees.email_required', 'The email field is required.');
        }
        if (!employee && !/^(?=.*[A-Z])(?=.*\d)[^\s]{8,72}$/.test(form.data.password || '')) {
            errors.password = t('admin.employees.password_rules', '8–72 characters, with a capital letter and a number, no spaces.');
        }
        if (!form.data.is_super && !form.data.team_id) {
            errors.team_id = t('admin.employees.team_required', 'Choose a team.');
        }
        if (Object.keys(errors).length) {
            form.setError(errors);
            return;
        }
        if (employee) {
            form.put(`/admin/staff/employees/${employee.id}`);
        } else {
            form.post('/admin/staff/employees');
        }
    };

    const inlineErrors = ['name', 'email', 'password', 'job_title', 'phone', 'team_id'];

    return (
        <Layout>
            <PageHeader
                title={employee ? t('admin.employees.edit_employee', 'Edit employee') : t('admin.employees.add_employee', 'Add employee')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/staff/employees', label: t('admin.employees.employees', 'Employees') },
                    { label: employee ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    employee && !lastSuper ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(`Delete “${employee.name}”?`)) {
                                    router.delete(`/admin/staff/employees/${employee.id}`);
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
                {!form.data.is_super ? <StaffAccessFlow highlight="employee" /> : null}

                <div className={ui.formLayout}>
                    <div className={ui.formMain}>
                        <FormErrors errors={form.errors} except={inlineErrors} />

                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.employees.account', 'Account')}</h2>
                            </div>
                            <div className="grid gap-3">
                                <Field label={t('admin.employees.name', 'Name')} error={form.errors.name} required>
                                    <Input
                                        wide
                                        invalid={!!form.errors.name}
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder={t('admin.employees.jane_patel', 'Jane Patel')}
                                    />
                                </Field>
                                <Field label={t('admin.employees.email', 'Email')} error={form.errors.email} required>
                                    <Input
                                        wide
                                        type="email"
                                        invalid={!!form.errors.email}
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        placeholder={t('admin.employees.jane_store_test', 'jane@store.test')}
                                    />
                                </Field>
                                <Field
                                    label={employee ? t('admin.employees.new_password', 'New password') : t('admin.employees.password', 'Password')}
                                    hint={employee ? t('admin.employees.leave_blank_to_keep_the_current_password', 'Leave blank to keep the current password.') : t('admin.employees.password_rules', '8–72 characters, with a capital letter and a number, no spaces.')}
                                    error={form.errors.password}
                                    required={!employee}
                                    optional={!!employee}
                                >
                                    <Input
                                        wide
                                        type="password"
                                        autoComplete="new-password"
                                        invalid={!!form.errors.password}
                                        value={form.data.password}
                                        onChange={(e) => form.setData('password', e.target.value)}
                                    />
                                </Field>
                                <div className={ui.fieldGrid}>
                                    <Field label={t('admin.employees.job_title', 'Job title')} error={form.errors.job_title} optional>
                                        <Input
                                            wide
                                            invalid={!!form.errors.job_title}
                                            value={form.data.job_title}
                                            onChange={(e) => form.setData('job_title', e.target.value)}
                                            placeholder={t('admin.employees.catalog_editor', 'Catalog editor')}
                                        />
                                    </Field>
                                    <Field label={t('admin.employees.phone', 'Phone')} error={form.errors.phone} optional>
                                        <Input
                                            wide
                                            invalid={!!form.errors.phone}
                                            value={form.data.phone}
                                            onChange={(e) => form.setData('phone', e.target.value)}
                                        />
                                    </Field>
                                </div>
                            </div>
                        </section>

                        {!form.data.is_super ? (
                            <section className={ui.sectionCard}>
                                <div className={ui.sectionHead}>
                                    <h2 className={ui.sectionTitle}>{t('admin.employees.access', 'Access')}</h2>
                                </div>
                                <p className={`${ui.metaK} mb-4`}>
                                    {t(
                                        'admin.employees.team_profile_access',
                                        'Choose a team. That team’s profile decides which admin pages this employee can open.',
                                    )}
                                </p>
                                <div className="grid gap-3">
                                    <Field label={t('admin.employees.team', 'Team')} error={form.errors.team_id} required>
                                        <Select
                                            wide
                                            invalid={!!form.errors.team_id}
                                            value={form.data.team_id || ''}
                                            onChange={(e) => form.setData('team_id', e.target.value)}
                                            required
                                        >
                                            <option value="">{t('admin.employees.choose_a_team', 'Choose a team')}</option>
                                            {teams.map((team) => (
                                                <option key={team.id} value={team.id}>{team.name}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                    {selectedTeam?.default_profile ? (
                                        <div className={`${ui.sectionCard} bg-neutral-50/80`}>
                                            <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                                {t('admin.employees.profile_from_team', 'Profile from team')}
                                            </p>
                                            <p className="mt-1 font-medium text-neutral-900">{selectedTeam.default_profile.name}</p>
                                            {selectedTeam.default_profile.description ? (
                                                <p className={`${ui.muted} mt-1`}>{selectedTeam.default_profile.description}</p>
                                            ) : null}
                                        </div>
                                    ) : form.data.team_id ? (
                                        <p className={ui.fieldError}>
                                            {t('admin.employees.team_has_no_profile', 'This team has no profile yet. Edit the team and choose a profile first.')}
                                        </p>
                                    ) : null}
                                </div>
                            </section>
                        ) : null}
                    </div>

                    <div className={ui.formSide}>
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.employees.status', 'Status')}</h2>
                            </div>
                            <Checkbox
                                label={t('admin.employees.can_sign_in', 'Can sign in')}
                                checked={!!form.data.is_enabled}
                                disabled={lastSuper && form.data.is_enabled}
                                onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            />
                            <p className={`${ui.metaK} mt-2`}>{t('admin.employees.disabled_accounts_are_rejected_at_login', 'Disabled accounts are rejected at login.')}</p>
                        </section>

                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.employees.super_admin', 'Super admin')}</h2>
                            </div>
                            <Checkbox
                                label={t('admin.employees.this_person_is_a_super_admin', 'This person is a super admin')}
                                checked={!!form.data.is_super}
                                disabled={lastSuper}
                                onChange={(e) => form.setData('is_super', e.target.checked)}
                            />
                            <p className={`${ui.metaK} mt-2`}>
                                {t('admin.employees.super_admins_can_manage_staff', 'Super admins can manage Staff (employees, teams, and profiles). Keep at least one.')}
                            </p>
                        </section>

                        <section className={ui.sectionCard}>
                            <div className={ui.actionList}>
                                <Button icon="check" busy={form.processing} type="submit">
                                    {employee ? t('admin.common.save', 'Save changes') : t('admin.employees.create_employee', 'Create employee')}
                                </Button>
                                <Button type="button" variant="secondary" href="/admin/staff/employees">
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
