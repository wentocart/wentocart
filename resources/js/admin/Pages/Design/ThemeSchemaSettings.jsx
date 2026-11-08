import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Alert, FormErrors, PageHeader, StatusTabs, ui, t,
} from '../../ui';
import SchemaGroupPanel from './themeSchema/SchemaGroupPanel';
import SchemaSidebar from './themeSchema/SchemaSidebar';
import ThemeSchemaSummary from './themeSchema/ThemeSchemaSummary';
import { buildTabPlan, setByPath } from './themeSchema/utils';

export default function ThemeSchemaSettings({ settings, schema, shop }) {
    const form = useForm({ settings: settings || {} });
    const groups = useMemo(
        () => (schema?.groups || []).filter((group) => group && Array.isArray(group.fields)),
        [schema],
    );
    const tabs = useMemo(() => buildTabPlan(groups), [groups]);

    const [activeTab, setActiveTab] = useState(tabs[0]?.key || 'layout');
    const activeTabPlan = tabs.find((tab) => tab.key === activeTab) || tabs[0];
    const tabGroups = activeTabPlan?.groups || [];
    const [activeGroupId, setActiveGroupId] = useState(tabGroups[0]?.id || null);

    useEffect(() => {
        if (!tabGroups.length) {
            setActiveGroupId(null);
            return;
        }
        if (!tabGroups.some((group) => group.id === activeGroupId)) {
            setActiveGroupId(tabGroups[0].id);
        }
    }, [activeTab, tabGroups, activeGroupId]);

    const activeGroup = tabGroups.find((group) => group.id === activeGroupId) || tabGroups[0];

    const setPath = (path, value) => {
        form.setData('settings', setByPath(form.data.settings, path, value));
    };

    const submit = (e) => {
        e.preventDefault();
        form.put('/admin/themes/settings', { preserveScroll: true });
    };

    const reset = () => {
        if (!window.confirm(t('admin.theme_settings.reset_confirm', 'Reset all theme customizations to theme defaults?'))) return;
        router.post('/admin/themes/settings/reset', {}, { preserveScroll: true });
    };

    const onTabChange = (nextTab) => {
        setActiveTab(nextTab);
        const nextGroups = tabs.find((tab) => tab.key === nextTab)?.groups || [];
        setActiveGroupId(nextGroups[0]?.id || null);
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.theme_settings.title', 'Theme settings')}
                crumbs={[
                    { label: t('admin.nav.design', 'Design'), href: '/admin/themes' },
                    { label: t('admin.themes.title', 'Themes'), href: '/admin/themes' },
                    { label: t('admin.theme_settings.title', 'Theme settings') },
                ]}
            />

            <Alert type="info" className="mb-4">
                {t(
                    'admin.theme_schema.active_theme',
                    `Customize the storefront homepage for ${shop?.theme_label || shop?.theme || 'this theme'}. Changes apply to this shop only.`,
                )}
            </Alert>

            {shop?.storefront_settings_apply === 'admin_only' ? (
                <Alert type="warning" className="mb-4">
                    {t(
                        'admin.theme_schema.admin_only_storefront',
                        'This theme’s live storefront is still on its built-in static layout. You can edit and save settings here; they are stored for this shop and will apply when the theme is wired to the storefront.',
                    )}
                </Alert>
            ) : null}

            <StatusTabs
                className="mb-4 rounded-xl border border-neutral-200 bg-white px-2"
                tabs={tabs.map((tab) => ({
                    key: tab.key,
                    label: tab.label,
                    count: tab.groups.length,
                }))}
                value={activeTab}
                onChange={onTabChange}
            />

            {activeTabPlan?.description ? (
                <p className={`${ui.sectionLead} mb-4`}>{activeTabPlan.description}</p>
            ) : null}

            <form onSubmit={submit} className={ui.formLayoutWide}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    {tabGroups.length > 1 ? (
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,220px)_minmax(0,1fr)]">
                            <SchemaSidebar
                                groups={tabGroups}
                                activeGroupId={activeGroup?.id}
                                onSelect={setActiveGroupId}
                            />
                            {activeGroup ? (
                                <SchemaGroupPanel
                                    group={activeGroup}
                                    settings={form.data.settings}
                                    onSetPath={setPath}
                                />
                            ) : null}
                        </div>
                    ) : activeGroup ? (
                        <SchemaGroupPanel
                            group={activeGroup}
                            settings={form.data.settings}
                            onSetPath={setPath}
                        />
                    ) : (
                        <div className={ui.sectionCard}>
                            <p className={ui.muted}>{t('admin.theme_schema.empty_tab', 'No settings in this tab for this theme.')}</p>
                        </div>
                    )}
                </div>

                <aside className={ui.formSide}>
                    <ThemeSchemaSummary
                        shop={shop}
                        onReset={reset}
                        processing={form.processing}
                    />
                </aside>
            </form>
        </Layout>
    );
}
