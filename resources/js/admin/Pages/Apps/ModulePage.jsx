import { useEffect } from 'react';
import { router, useForm, Head } from '@inertiajs/react';
import Layout from '../Layout';
import { Alert, Button, Card, FormErrors, KpiCards, PageHeader, ModuleSlots, ui, t } from '../../ui';
import ExtensionPrimaryNav from './ExtensionPrimaryNav';
import ExtensionSettingsFields from './ExtensionSettingsFields';
import ExtensionTabbedSettings from './ExtensionTabbedSettings';
import ModuleHostedList, { moduleListHeaderActions } from './ModuleHostedList';
import { moduleHint, moduleLabel, moduleTitle } from './moduleLabel';

function documentBannerErrors(document = {}, formErrors = {}) {
    const banner = {};
    (document.errors || []).forEach((message, index) => {
        banner[`form_${index}`] = message;
    });

    return { ...banner, ...formErrors };
}

export default function ModulePage({ app, page, document = {}, css = [], js = [], values = {}, masked = {} }) {
    const form = useForm({ values });
    const fields = document.fields || [];
    const tabs = document.tabs || [];
    const blocks = document.blocks || [];
    const list = document.list || null;
    const nav = document.nav || [];
    const hasTabs = tabs.length > 0;
    const hasNav = nav.length > 0;
    const hasList = Boolean(list) && !hasTabs;
    const fieldErrors = document.field_errors || {};
    const banner = documentBannerErrors(document, form.errors);
    const bannerAtBottom = document.error_placement === 'bottom';
    const errorBanner = <FormErrors errors={banner} />;

    useEffect(() => {
        form.setData('values', values);
    }, [values]);

    useEffect(() => {
        if (document.reload) {
            router.reload({ preserveScroll: false });
            return;
        }
        if (document.redirect) {
            router.visit(document.redirect);
        }
    }, [document.reload, document.redirect]);

    const submit = (event) => {
        event.preventDefault();
        const search = typeof window !== 'undefined' ? window.location.search : '';
        form.post(`/admin/modules/${app.vendor}/${app.name}/${page}${search}`, {
            forceFormData: true,
        });
    };

    const headerActions = (
        <div className={ui.toolbar}>
            <Button variant="secondary" href="/admin/extensions">
                {t('admin.apps.manage_extensions', 'Manage extensions')}
            </Button>
            {document.back?.href ? (
                <Button variant="secondary" icon={document.back.icon || 'arrow-left'} href={document.back.href}>
                    {moduleLabel(document.back, document.back.label)}
                </Button>
            ) : null}
            {hasList ? moduleListHeaderActions(list) : null}
        </div>
    );

    const pageHeader = (
        <PageHeader
            title={moduleTitle(document, app.display_name)}
            total={hasList ? list.rows?.length : undefined}
            crumbs={[
                { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                { href: '/admin/extensions', label: t('admin.apps.apps', 'Extensions') },
                { href: `/admin/extensions/${app.vendor}/${app.name}`, label: app.display_name },
                { label: moduleTitle(document, page) },
            ]}
            actions={headerActions}
            crumbsFirst
        />
    );

    const pageBody = (
        <>
                {(document.kpis || []).length ? <KpiCards items={document.kpis} /> : null}
                {moduleHint(document) ? <Alert>{moduleHint(document)}</Alert> : null}
                {errorBanner && (hasList || (!hasTabs && fields.length === 0)) ? errorBanner : null}
                {!hasTabs && blocks.length > 0 ? <ModuleSlots slots={blocks} /> : null}
                {hasList ? <ModuleHostedList app={app} page={page} list={list} /> : null}
                {hasTabs ? (
                    <>
                        <form className={ui.formPageShell} onSubmit={submit}>
                            <ExtensionTabbedSettings
                                header={(
                                    <>
                                        {pageHeader}
                                        {!bannerAtBottom ? errorBanner : null}
                                    </>
                                )}
                                tabs={tabs}
                                nav={nav}
                                sidebarActions={document.sidebar_actions || []}
                                values={form.data.values}
                                masked={masked}
                                errors={fieldErrors}
                                processing={form.processing}
                                onChange={(name, value) => form.setData('values', { ...form.data.values, [name]: value })}
                            />
                            {bannerAtBottom ? errorBanner : null}
                        </form>
                        {blocks.length > 0 ? (
                            <div className="mt-5">
                                <ModuleSlots slots={blocks} />
                            </div>
                        ) : null}
                    </>
                ) : fields.length > 0 ? (
                    <Card as="form" className="space-y-4" onSubmit={submit}>
                        <h2 className={ui.sectionTitle}>
                            {document.form_title
                                ? moduleLabel({ label: document.form_title, label_key: document.form_title_key }, document.form_title)
                                : t('admin.apps.module_form', 'Settings')}
                        </h2>
                        {!bannerAtBottom ? errorBanner : null}
                        <ExtensionSettingsFields
                            fields={fields}
                            values={form.data.values}
                            masked={masked}
                            errors={fieldErrors}
                            layout={document.layout || 'stack'}
                            onChange={(name, value) => form.setData('values', { ...form.data.values, [name]: value })}
                        />
                        {bannerAtBottom ? errorBanner : null}
                        <div className={document.layout === 'config' ? ui.configRow : undefined}>
                            {document.layout === 'config' ? <div className={ui.configLabelCol} /> : null}
                            <div className={document.layout === 'config' ? ui.configFieldCol : undefined}>
                                <Button type="submit" icon="check" busy={form.processing}>
                                    {document.save_label
                                        ? moduleLabel({ label: document.save_label, label_key: document.save_label_key }, document.save_label)
                                        : t('admin.apps.save', 'Save settings')}
                                </Button>
                            </div>
                        </div>
                    </Card>
                ) : null}
        </>
    );

    return (
        <Layout mainClassName={hasTabs || hasNav ? ui.mainFlushTop : undefined}>
            <Head>
                {css.map((href) => <link key={href} rel="stylesheet" href={href} />)}
            </Head>
            {hasNav && !hasTabs ? (
                <div className={ui.formPageShell}>
                    <div className={ui.formPageStickyHead}>
                        {pageHeader}
                        <ExtensionPrimaryNav items={nav} />
                    </div>
                    <div className={`${ui.extensionFormPageBody} space-y-4`}>
                        {pageBody}
                    </div>
                </div>
            ) : (
                <>
                    {hasTabs ? null : pageHeader}
                    <div className={hasTabs ? undefined : 'space-y-4'}>
                        {pageBody}
                    </div>
                </>
            )}
            {js.map((src) => <script key={src} src={src} defer />)}
        </Layout>
    );
}
