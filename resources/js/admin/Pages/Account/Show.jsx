import { Link } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Alert,
    Avatar,
    Badge,
    Button,
    Icon,
    PageHeader,
    ui,
    t,
} from '../../ui';

function formatDate(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function Row({ label, children }) {
    return (
        <div className={ui.detailRow}>
            <span className={ui.detailLabel}>{label}</span>
            <span className={ui.detailValue}>{children || '—'}</span>
        </div>
    );
}

function dash(value) {
    const text = typeof value === 'string' ? value.trim() : value;
    return text || '—';
}

export default function Show({ account }) {
    const accessLabel = account.is_super
        ? t('admin.shell.super_admin', 'Super admin')
        : t('admin.account.staff', 'Staff');
    const signInLabel = account.is_enabled
        ? t('admin.account.can_sign_in', 'Can sign in')
        : t('admin.account.sign_in_disabled', 'Sign-in disabled');
    const roles = account.roles || [];

    return (
        <Layout>
            <PageHeader
                title={t('admin.account.my_profile', 'My profile')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.account.my_profile', 'My profile') },
                ]}
                actions={(
                    <Button native href="/admin/account/edit" icon="pencil">
                        {t('admin.account.edit_profile', 'Edit profile')}
                    </Button>
                )}
            />

            {!account.has_avatar ? (
                <Alert type="warning" className="mb-4">
                    {t('admin.account.logo_required_hint', 'Add a profile photo / logo so your account is complete.')}
                    {' '}
                    <Link href="/admin/account/edit" className="font-medium underline">
                        {t('admin.account.edit_profile', 'Edit profile')}
                    </Link>
                </Alert>
            ) : null}

            <div className={ui.formLayout}>
                <div className={ui.formMain}>
                    <section className={ui.sectionCard}>
                        <div className="flex flex-wrap items-start gap-4">
                            <Avatar name={account.name} src={account.avatar_url} className={ui.avatarLg} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-lg font-semibold text-neutral-900">{account.name}</h2>
                                    <Badge tone={account.is_super ? 'primary' : 'neutral'}>{accessLabel}</Badge>
                                    <Badge tone={account.is_enabled ? 'success' : 'danger'}>{signInLabel}</Badge>
                                </div>
                                <p className={`${ui.muted} mt-1`}>{account.email}</p>
                                {account.job_title ? (
                                    <p className="mt-1 text-sm text-neutral-700">{account.job_title}</p>
                                ) : null}
                            </div>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.contact', 'Contact')}</h2>
                        </div>
                        <Row label={t('admin.account.first_name', 'First name')}>{dash(account.first_name)}</Row>
                        <Row label={t('admin.account.last_name', 'Last name')}>{dash(account.last_name)}</Row>
                        <Row label={t('admin.employees.email', 'Email')}>{account.email}</Row>
                        <Row label={t('admin.employees.phone', 'Phone')}>{dash(account.phone)}</Row>
                        <Row label={t('admin.employees.job_title', 'Job title')}>{dash(account.job_title)}</Row>
                        {account.note ? (
                            <Row label={t('admin.account.note', 'Note')}>
                                <span className="max-w-md whitespace-pre-wrap text-left">{account.note}</span>
                            </Row>
                        ) : (
                            <Row label={t('admin.account.note', 'Note')}>—</Row>
                        )}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.organization', 'Organization')}</h2>
                        </div>
                        <Row label={t('admin.employees.team', 'Team')}>
                            <span className={ui.cellStack}>
                                <span>{dash(account.team?.name)}</span>
                                {account.team?.description ? (
                                    <span className={ui.cellSub}>{account.team.description}</span>
                                ) : null}
                            </span>
                        </Row>
                        <Row label={t('admin.employees.profile', 'Profile')}>
                            <span className={ui.cellStack}>
                                <span>{dash(account.profile?.name)}</span>
                                {account.profile?.description ? (
                                    <span className={ui.cellSub}>{account.profile.description}</span>
                                ) : null}
                            </span>
                        </Row>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.access', 'Access')}</h2>
                        </div>
                        <Row label={t('admin.account.access_level', 'Access level')}>{accessLabel}</Row>
                        <Row label={t('admin.account.roles', 'Roles')}>
                            {roles.length
                                ? (
                                    <span className="flex flex-wrap justify-end gap-1.5">
                                        {roles.map((role) => (
                                            <Badge key={role.slug || role.name} tone="neutral" dot={false}>
                                                {role.name}
                                            </Badge>
                                        ))}
                                    </span>
                                )
                                : (account.is_super ? t('admin.shell.super_admin', 'Super admin') : '—')}
                        </Row>
                        <Row label={t('admin.account.permissions', 'Permissions')}>
                            {account.is_super
                                ? t('admin.account.full_access', 'Full admin access')
                                : (account.permission_count != null
                                    ? `${account.permission_count} ${t('admin.account.pages', 'pages')}`
                                    : '—')}
                        </Row>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.account_summary', 'Account summary')}</h2>
                        </div>
                        <div className={ui.statCard}>
                            <div className={`${ui.statIcon} ${ui.statIconPrimary}`}>
                                <Icon name="users" />
                            </div>
                            <div className={ui.statBody}>
                                <div className={ui.statLabel}>{t('admin.account.employee_id', 'Employee ID')}</div>
                            </div>
                            <div className={ui.statValue}>#{account.id}</div>
                        </div>
                        <div className={`${ui.sectionSplit} space-y-0`}>
                            <Row label={t('admin.account.photo', 'Photo / logo')}>
                                {account.has_avatar
                                    ? t('admin.account.photo_on_file', 'On file')
                                    : t('admin.account.photo_missing', 'Missing')}
                            </Row>
                            <Row label={t('admin.account.member_since', 'Member since')}>
                                {formatDate(account.created_at)}
                            </Row>
                            <Row label={t('admin.account.last_updated', 'Last updated')}>
                                {formatDate(account.updated_at)}
                            </Row>
                            <Row label={t('admin.employees.status', 'Status')}>{signInLabel}</Row>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.quick_actions', 'Quick actions')}</h2>
                        </div>
                        <div className={ui.actionList}>
                            <Link href="/admin/account/edit" className={ui.actionItem}>
                                <Icon name="pencil" />
                                {t('admin.account.edit_profile', 'Edit profile')}
                            </Link>
                            <Link href="/admin" className={ui.actionItem}>
                                <Icon name="bar-chart" />
                                {t('admin.nav.dashboard', 'Dashboard')}
                            </Link>
                        </div>
                    </section>
                </div>
            </div>
        </Layout>
    );
}
