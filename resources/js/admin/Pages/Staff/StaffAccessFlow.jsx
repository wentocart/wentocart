import { ui, t } from '../../ui';

const STEPS = [
    {
        key: 'employee',
        title: t('admin.staff.flow.employee', 'Employee'),
        hint: t('admin.staff.flow.employee_hint', 'A person who signs in to admin.'),
    },
    {
        key: 'team',
        title: t('admin.staff.flow.team', 'Team'),
        hint: t('admin.staff.flow.team_hint', 'Org group the employee belongs to.'),
    },
    {
        key: 'profile',
        title: t('admin.staff.flow.profile', 'Profile'),
        hint: t('admin.staff.flow.profile_hint', 'Each team has one profile that lists admin pages.'),
    },
    {
        key: 'pages',
        title: t('admin.staff.flow.pages', 'Admin pages'),
        hint: t('admin.staff.flow.pages_hint', 'Tick the screens this profile may open.'),
    },
    {
        key: 'super',
        title: t('admin.staff.flow.super', 'Super admin'),
        hint: t('admin.staff.flow.super_hint', 'Bypasses profiles — every page, including Staff.'),
        active: false,
    },
];

export default function StaffAccessFlow({ highlight = 'profile' }) {
    return (
        <section className={ui.sectionCard}>
            <div className="mb-4">
                <p className={ui.sectionKicker}>{t('admin.staff.flow.kicker', 'How access works')}</p>
                <h2 className={ui.sectionTitle}>{t('admin.staff.flow.title', 'Employee → Team → Profile → Pages')}</h2>
                <p className={ui.sectionLead}>
                    {t(
                        'admin.staff.flow.lead',
                        'Pick a team on the employee. That team’s profile decides which admin screens they can open. Edit permissions on the profile.',
                    )}
                </p>
            </div>
            <div className={ui.staffFlow}>
                {STEPS.map((step, index) => {
                    const isActive = step.key === highlight;
                    return (
                        <div key={step.key} className={isActive ? ui.staffFlowStepActive : ui.staffFlowStep}>
                            <span className={isActive ? ui.staffFlowNumActive : ui.staffFlowNum}>{index + 1}</span>
                            <div className={ui.staffFlowTitle}>{step.title}</div>
                            <div className={ui.staffFlowHint}>{step.hint}</div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
