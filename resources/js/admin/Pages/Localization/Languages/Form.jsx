import { useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import { Button, Checkbox, Field, FormErrors, Input, PageHeader, ui, t } from '../../../ui';

export default function Form({ language }) {
    const form = useForm({
        code: language?.code || '',
        name: language?.name || '',
        is_enabled: language?.is_enabled ?? true,
        is_default: language?.is_default ?? false,
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            code: String(data.code || '').toLowerCase(),
            is_enabled: !!data.is_enabled,
            is_default: !!data.is_default,
        }));
        if (language) {
            form.put(`/admin/localization/languages/${language.id}`);
        } else {
            form.post('/admin/localization/languages');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={language ? t('admin.common.edit', 'Edit') : t('admin.languages.add', 'Add language')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.nav.localization', 'Localization') },
                    { href: '/admin/localization/languages', label: t('admin.languages.title', 'Languages') },
                    { label: language ? t('admin.common.edit', 'Edit') : t('admin.common.create', 'Create') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization/languages">
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                        <Button type="submit" form="language-form" icon="check" busy={form.processing}>
                            {t('admin.common.save', 'Save changes')}
                        </Button>
                    </div>
                )}
                className="mb-6"
            />
            <form id="language-form" onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}><h2 className={ui.sectionTitle}>{t('admin.languages.title', 'Languages')}</h2></div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.languages.code', 'Code')} error={form.errors.code} hint={t('admin.languages.iso_639_e_g_hi_or_pt_br', 'ISO 639, e.g. hi or pt-br')} required>
                                <Input
                                    wide
                                    disabled={!!language}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.toLowerCase())}
                                    maxLength={8}
                                    placeholder="hi"
                                />
                            </Field>
                            <Field label={t('admin.languages.name', 'Name')} error={form.errors.name} required>
                                <Input wide value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('admin.languages.hindi', 'Hindi')} />
                            </Field>
                        </div>
                    </section>
                </div>
                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}><h2 className={ui.sectionTitle}>{t('admin.common.active', 'Active')}</h2></div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            disabled={!!form.data.is_default}
                        />
                        <div className="mt-3">
                            <Checkbox
                                label={t('admin.languages.default', 'Default')}
                                checked={!!form.data.is_default}
                                onChange={(e) => form.setData({
                                    ...form.data,
                                    is_default: e.target.checked,
                                    is_enabled: e.target.checked ? true : form.data.is_enabled,
                                })}
                            />
                        </div>
                        <p className={`${ui.muted} mt-3`}>
                            {t('admin.languages.cannot_disable_default', 'Cannot deactivate the default language.')}
                        </p>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
