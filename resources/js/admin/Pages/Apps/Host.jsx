import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { Alert, Button, Card, FormErrors, PageHeader, ModuleSlots, ui, t } from '../../ui';
import ExtensionSettingsFields from './ExtensionSettingsFields';
import ExtensionTabbedSettings from './ExtensionTabbedSettings';

function scrollAdminContentToTop() {
    document.querySelector('main')?.scrollTo({ top: 0, behavior: 'smooth' });
}

function hasFileValues(obj) {
    if (!obj || typeof obj !== 'object') return false;
    for (const key of Object.keys(obj)) {
        const val = obj[key];
        if (typeof File !== 'undefined' && val instanceof File) return true;
        if (typeof Blob !== 'undefined' && val instanceof Blob) return true;
        if (val && typeof val === 'object' && hasFileValues(val)) return true;
    }
    return false;
}

export default function Host({ app, schema = {}, values = {}, masked = {} }) {
    const form = useForm({ values });
    const fields = schema.fields || [];
    const tabs = schema.tabs || [];
    const hasTabs = tabs.length > 0;
    const fieldErrors = schema.inline_errors === false
        ? Object.fromEntries(Object.keys(form.errors || {}).map((key) => [key, true]))
        : form.errors;

    function saveSettings(event) {
        event.preventDefault();
        scrollAdminContentToTop();
        const url = `/admin/extensions/${app.vendor}/${app.name}`;
        if (hasFileValues(form.data.values)) {
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(url, {
                forceFormData: true,
                onFinish: () => {
                    form.transform((data) => data);
                    scrollAdminContentToTop();
                },
            });
        } else {
            form.transform((data) => data);
            form.put(url, {
                onFinish: scrollAdminContentToTop,
            });
        }
    }

    const crumbs = [
        { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
        { href: '/admin/extensions', label: t('admin.apps.apps', 'Extensions') },
        { label: app.display_name },
    ];
    const pageHeader = (
        <PageHeader
            title={schema.title || app.display_name}
            crumbs={crumbs}
            crumbsFirst
            actions={(
                <Button variant="secondary" href="/admin/extensions">
                    {t('admin.apps.manage_extensions', 'Manage extensions')}
                </Button>
            )}
        />
    );

    return (
        <Layout mainClassName={hasTabs ? ui.mainFlushTop : undefined}>
            {hasTabs ? null : pageHeader}
            <div className={hasTabs ? undefined : 'max-w-3xl space-y-5'}>
                {!hasTabs ? (
                    <Alert>
                        {t('admin.apps.host_hint', 'This screen is rendered by core from a field list the extension declared. The extension cannot inject admin JavaScript.')}
                    </Alert>
                ) : null}
                {hasTabs ? null : (
                    <>
                        {!app.enabled ? (
                            <Alert type="warning">{t('admin.apps.disabled_hint', 'Enable this extension on the Extensions list before the storefront will use it.')}</Alert>
                        ) : null}
                        {app.last_error ? (
                            <Alert type="error">{app.last_error}</Alert>
                        ) : null}
                        {(app.setup_steps || []).length > 0 ? (
                            <Card className="space-y-2">
                                <h2 className={ui.sectionTitle}>{t('admin.apps.setup_steps', 'Setup')}</h2>
                                <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                                    {app.setup_steps.map((step) => (
                                        <li key={step}>{step}</li>
                                    ))}
                                </ol>
                            </Card>
                        ) : null}
                    </>
                )}
                {hasTabs ? (
                    <>
                        <form autoComplete="off" className={ui.formPageShell} onSubmit={saveSettings}>
                        <ExtensionTabbedSettings
                            header={(
                                <>
                                    {pageHeader}
                                    {!app.enabled ? (
                                        <Alert type="warning">{t('admin.apps.disabled_hint', 'Enable this extension on the Extensions list before the storefront will use it.')}</Alert>
                                    ) : null}
                                    {app.last_error ? (
                                        <Alert type="error">{app.last_error}</Alert>
                                    ) : null}
                                    {(app.setup_steps || []).length > 0 ? (
                                        <Card className="mb-2 space-y-2">
                                            <h2 className={ui.sectionTitle}>{t('admin.apps.setup_steps', 'Setup')}</h2>
                                            <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                                                {app.setup_steps.map((step) => (
                                                    <li key={step}>{step}</li>
                                                ))}
                                            </ol>
                                        </Card>
                                    ) : null}
                                    <FormErrors className="mb-2" errors={form.errors} />
                                </>
                            )}
                            tabs={tabs}
                            nav={schema.nav || []}
                            sidebarActions={schema.sidebar_actions || []}
                            values={form.data.values}
                            masked={masked}
                            errors={fieldErrors}
                            processing={form.processing}
                            onChange={(name, value) => form.setData('values', { ...form.data.values, [name]: value })}
                        />
                    </form>
                    {(schema.blocks || []).length > 0 ? (
                        <div className="mt-5">
                            <ModuleSlots slots={schema.blocks} />
                        </div>
                    ) : null}
                </>
                ) : (
                    <Card as="form" autoComplete="off" className="space-y-5" onSubmit={saveSettings}>
                        <p className={ui.muted}>{schema.hint || app.description}</p>
                        <FormErrors errors={form.errors} />
                        {fields.length === 0 ? (
                            <p className={ui.muted}>{t('admin.apps.no_settings', 'This extension has no settings.')}</p>
                        ) : (
                            <ExtensionSettingsFields
                                fields={fields}
                                values={form.data.values}
                                masked={masked}
                                errors={fieldErrors}
                                layout={schema.layout || 'stack'}
                                onChange={(name, value) => form.setData('values', { ...form.data.values, [name]: value })}
                            />
                        )}
                        {fields.length > 0 ? (
                            <Button type="submit" icon="check" busy={form.processing}>{t('admin.apps.save', 'Save settings')}</Button>
                        ) : null}
                    </Card>
                )}
            </div>
        </Layout>
    );
}
