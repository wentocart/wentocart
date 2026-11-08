import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { Alert, Badge, Button, Card, Field, FormErrors, PageHeader, ui, t } from '../../ui';

export default function Themes({ themes = [], active, shop }) {
    const form = useForm({ zip: null });

    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/themes', { forceFormData: true });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.themes.themes', 'Themes')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/cms', label: t('admin.nav.design', 'Design') },
                    { label: t('admin.themes.themes', 'Themes') },
                ]}
                actions={(
                    <Button href="/admin/themes/settings" variant="secondary" icon="settings">
                        {t('admin.themes.customize', 'Customize active theme')}
                    </Button>
                )}
            />
            <div className="max-w-5xl space-y-4">
                <Alert>
                    {t('admin.themes.hint', 'Classic is the official storefront. Customize colors and homepage sections from Theme settings, or upload a child zip to restyle further.')}
                    {shop?.name ? ` ${t('admin.themes.editing_shop', 'Editing shop')}: ${shop.name}.` : ''}
                </Alert>

                <Card
                    as="form"
                    className="space-y-3"
                    onSubmit={submit}
                    encType="multipart/form-data"
                >
                    <h2 className={ui.sectionTitle}>{t('admin.themes.install_zip', 'Install from zip')}</h2>
                    <FormErrors errors={form.errors} />
                    <p className={ui.muted}>
                        {t('admin.themes.zip_hint', 'theme.json at the zip root. Parent classic extends Classic. Omit parent and ship dist/ to override everything.')}
                    </p>
                    <Field label={t('admin.themes.zip_file', 'Theme zip')} error={form.errors.zip}>
                        <input
                            type="file"
                            accept=".zip,application/zip"
                            className={ui.inputFull}
                            onChange={(e) => form.setData('zip', e.target.files?.[0] || null)}
                        />
                    </Field>
                    <Button type="submit" icon="check" busy={form.processing} disabled={!form.data.zip}>
                        {t('admin.themes.install', 'Install theme')}
                    </Button>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2">
                    {themes.map((theme) => (
                        <Card key={theme.name} className="space-y-3">
                            <div className="overflow-hidden rounded-lg bg-slate-100">
                                {theme.preview ? (
                                    <img src={theme.preview} alt="" className="h-40 w-full object-cover" />
                                ) : (
                                    <div className="flex h-40 items-center justify-center text-sm text-slate-500">
                                        {t('admin.themes.no_preview', 'No preview')}
                                    </div>
                                )}
                            </div>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-base font-semibold">{theme.display_name}</h3>
                                    <p className="text-xs text-slate-500">
                                        {theme.name} · v{theme.version} · {theme.author}
                                    </p>
                                </div>
                                {theme.active ? (
                                    <Badge tone="success">{t('admin.themes.active', 'Active')}</Badge>
                                ) : null}
                            </div>
                            <p className="text-sm text-slate-600">{theme.description}</p>
                            <p className="text-xs text-slate-500">
                                {theme.child
                                    ? t('admin.themes.extends', 'Extends') + ' ' + theme.parent
                                    : theme.full_override
                                        ? t('admin.themes.full_override', 'Full override zip')
                                        : t('admin.themes.official', 'Official Classic templates')}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {!theme.active ? (
                                    <>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => router.post('/admin/themes/preview', { theme: theme.name })}
                                        >
                                            {t('admin.themes.preview', 'Preview on storefront')}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => router.post('/admin/themes/enable', { theme: theme.name })}
                                        >
                                            {t('admin.themes.use_on_shop', 'Use on this shop')}
                                        </Button>
                                    </>
                                ) : (
                                    <Button href="/admin/themes/settings" variant="secondary">
                                        {t('admin.themes.customize', 'Customize')}
                                    </Button>
                                )}
                                {theme.can_delete ? (
                                    <Button
                                        type="button"
                                        variant="danger"
                                        onClick={() => {
                                            if (window.confirm(t('admin.themes.delete_confirm', 'Remove this installed theme?'))) {
                                                router.delete(`/admin/themes/${theme.name}`);
                                            }
                                        }}
                                    >
                                        {t('admin.themes.remove', 'Remove')}
                                    </Button>
                                ) : null}
                            </div>
                        </Card>
                    ))}
                </div>
            </div>
        </Layout>
    );
}
