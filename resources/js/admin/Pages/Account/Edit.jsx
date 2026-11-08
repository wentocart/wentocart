import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Avatar,
    Button,
    Field,
    FormErrors,
    ImageField,
    Input,
    PageHeader,
    Textarea,
    ui,
    t,
} from '../../ui';
import JumpShortcutCapture from '../../ui/JumpShortcutCapture';

export default function Edit({ account }) {
    const [preview, setPreview] = useState(account.avatar_url || null);
    const form = useForm({
        _method: 'put',
        first_name: account.first_name || '',
        last_name: account.last_name || '',
        email: account.email || '',
        current_password: '',
        password: '',
        job_title: account.job_title || '',
        phone: account.phone || '',
        note: account.note || '',
        avatar: null,
        jump_shortcut: account.jump_shortcut || '',
        jump_shortcut_clear: false,
    });

    const displayName = [form.data.first_name, form.data.last_name].filter(Boolean).join(' ') || account.name;

    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/account', { forceFormData: true });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.account.edit_profile', 'Edit profile')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/account', label: t('admin.account.my_profile', 'My profile') },
                    { label: t('admin.common.edit', 'Edit') },
                ]}
            />

            {!account.has_avatar ? (
                <Alert type="warning" className="mb-4">
                    {t('admin.account.logo_required_hint', 'Add a profile photo / logo so your account is complete.')}
                </Alert>
            ) : null}

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.account', 'Account')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <div className={ui.fieldGrid}>
                                <Field label={t('admin.account.first_name', 'First name')} error={form.errors.first_name}>
                                    <Input
                                        wide
                                        required
                                        autoComplete="given-name"
                                        invalid={!!form.errors.first_name}
                                        value={form.data.first_name}
                                        onChange={(e) => form.setData('first_name', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('admin.account.last_name', 'Last name')} error={form.errors.last_name}>
                                    <Input
                                        wide
                                        required
                                        autoComplete="family-name"
                                        invalid={!!form.errors.last_name}
                                        value={form.data.last_name}
                                        onChange={(e) => form.setData('last_name', e.target.value)}
                                    />
                                </Field>
                            </div>
                            <Field label={t('admin.employees.email', 'Email')} error={form.errors.email}>
                                <Input
                                    wide
                                    required
                                    type="email"
                                    autoComplete="email"
                                    invalid={!!form.errors.email}
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.security', 'Security')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field
                                label={t('admin.account.current_password', 'Password')}
                                hint={t('admin.account.current_password_hint', 'Required only when changing your password.')}
                                error={form.errors.current_password}
                            >
                                <Input
                                    wide
                                    type="password"
                                    autoComplete="current-password"
                                    invalid={!!form.errors.current_password}
                                    value={form.data.current_password}
                                    onChange={(e) => form.setData('current_password', e.target.value)}
                                />
                            </Field>
                            <Field
                                label={t('admin.employees.new_password', 'New password')}
                                hint={t('admin.employees.leave_blank_to_keep_the_current_password', 'Leave blank to keep the current password.')}
                                error={form.errors.password}
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
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.details', 'Details')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <div className={ui.fieldGrid}>
                                <Field label={t('admin.employees.job_title', 'Job title')} error={form.errors.job_title}>
                                    <Input
                                        wide
                                        value={form.data.job_title}
                                        onChange={(e) => form.setData('job_title', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('admin.employees.phone', 'Phone')} error={form.errors.phone}>
                                    <Input
                                        wide
                                        autoComplete="tel"
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                    />
                                </Field>
                            </div>
                            <Field
                                label={t('admin.account.note', 'Note')}
                                hint={t('admin.account.note_hint', 'Optional. Visible only on your profile.')}
                                error={form.errors.note}
                            >
                                <Textarea
                                    rows={4}
                                    value={form.data.note}
                                    onChange={(e) => form.setData('note', e.target.value)}
                                />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.search_shortcut.mine', 'My search shortcut')}</h2>
                        </div>
                        <p className={`${ui.muted} mb-3`}>
                            {t('admin.search_shortcut.mine_hint', 'Opens admin search from any page. Choose a modifier and a key, then save. Use shop default to follow General.')}
                        </p>
                        <Field label={t('admin.search_shortcut.label', 'Shortcut')} error={form.errors.jump_shortcut}>
                            <JumpShortcutCapture
                                value={form.data.jump_shortcut_clear ? '' : form.data.jump_shortcut}
                                fallback={form.data.jump_shortcut_clear
                                    ? (account.jump_shop || account.jump_default || 'ctrl+f')
                                    : (account.jump?.shortcut || 'ctrl+f')}
                                effectiveLabel={account.jump?.label}
                                effectiveSource={form.data.jump_shortcut && !form.data.jump_shortcut_clear ? 'staff' : account.jump?.source}
                                hint={t('admin.search_shortcut.capture_hint', 'Choose Ctrl, Alt, Window, or Shift, then a letter or number.')}
                                onChange={(chord) => {
                                    form.setData({
                                        ...form.data,
                                        jump_shortcut: chord,
                                        jump_shortcut_clear: false,
                                    });
                                }}
                                onClear={() => {
                                    form.setData({
                                        ...form.data,
                                        jump_shortcut: '',
                                        jump_shortcut_clear: true,
                                    });
                                }}
                            />
                        </Field>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.account.profile_picture', 'Profile picture')}</h2>
                        </div>
                        <div className="mb-4 flex items-center gap-3">
                            <Avatar name={displayName} src={preview} className={ui.avatarLg} />
                            <div className="min-w-0">
                                <div className={ui.cellStrong}>{displayName}</div>
                                <div className={ui.cellSub}>{account.email}</div>
                            </div>
                        </div>
                        <p className={`${ui.metaK} mb-3`}>
                            {t('admin.account.logo_hint', 'Required. Shown in the admin header next to your name.')}
                        </p>
                        <ImageField
                            label={t('admin.account.profile_picture', 'Profile picture')}
                            preview={preview}
                            error={form.errors.avatar}
                            onChange={(e) => {
                                const file = e.target.files?.[0] || null;
                                form.setData('avatar', file);
                                setPreview(file ? URL.createObjectURL(file) : account.avatar_url);
                            }}
                        />
                    </section>

                    <div className={ui.toolbar}>
                        <Button icon="check" busy={form.processing}>
                            {t('admin.common.save', 'Save changes')}
                        </Button>
                        <Button type="button" variant="secondary" native href="/admin/account">
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                    </div>
                </div>
            </form>
        </Layout>
    );
}
