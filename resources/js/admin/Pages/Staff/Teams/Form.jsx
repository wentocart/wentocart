import { router, useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import StaffAccessFlow from '../StaffAccessFlow';
import {
    Button,
    Checkbox,
    ColorField,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    Textarea,
    ui,
    t,
} from '../../../ui';

export default function Form({ team, profiles = [] }) {
    const form = useForm({
        name: team?.name || '',
        description: team?.description || '',
        color: team?.color || '#7C3AED',
        is_enabled: team?.is_enabled ?? true,
        default_profile_id: team?.default_profile_id || '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (team) {
            form.put(`/admin/staff/teams/${team.id}`);
        } else {
            form.post('/admin/staff/teams');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={team ? t('admin.teams.edit_team', 'Edit team') : t('admin.teams.add_team', 'Add team')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/staff/teams', label: t('admin.teams.teams', 'Teams') },
                    { label: team ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    team ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.teams.delete_this_team', 'Delete this team?'))) {
                                    router.delete(`/admin/staff/teams/${team.id}`);
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
                <StaffAccessFlow highlight="team" />

                <div className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.teams.team', 'Team')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.teams.name', 'Name')} error={form.errors.name}>
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.teams.operations', 'Operations')}
                                />
                            </Field>
                            <Field label={t('admin.teams.description', 'Description')} error={form.errors.description}>
                                <Textarea
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder={t('admin.teams.who_belongs_here', 'Who belongs here')}
                                />
                            </Field>
                            <Field label={t('admin.teams.color', 'Color')} error={form.errors.color}>
                                <ColorField value={form.data.color} onChange={(color) => form.setData('color', color)} />
                            </Field>
                            <Field label={t('admin.teams.profile', 'Profile')} hint={t('admin.teams.profile_hint', 'Employees on this team inherit this profile’s admin pages.')} error={form.errors.default_profile_id}>
                                <Select wide invalid={!!form.errors.default_profile_id} value={form.data.default_profile_id || ''} onChange={(e) => form.setData('default_profile_id', e.target.value)} required>
                                    <option value="">{t('admin.teams.choose_a_profile', 'Choose a profile')}</option>
                                    {profiles.map((profile) => (
                                        <option key={profile.id} value={profile.id}>{profile.name}</option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </section>
                </div>
                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.teams.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                    </section>
                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {team ? t('admin.common.save', 'Save changes') : t('admin.teams.create_team', 'Create team')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/staff/teams">{t('admin.common.cancel', 'Cancel')}</Button>
                        </div>
                    </section>
                </div>
                </div>
            </form>
        </Layout>
    );
}
