import { useState } from 'react';
import { Button, ModuleSlots, PanelTabs, ui, t } from '../../ui';
import ExtensionPrimaryNav from './ExtensionPrimaryNav';
import ExtensionSettingsFields from './ExtensionSettingsFields';
import { moduleLabel } from './moduleLabel';

export default function ExtensionTabbedSettings({
    tabs = [],
    nav = [],
    sidebarActions = [],
    values = {},
    masked = {},
    errors = {},
    onChange,
    processing = false,
    header = null,
}) {
    const visibleTabs = tabs.filter((tab) => tab?.id);
    const [activeId, setActiveId] = useState(visibleTabs[0]?.id || '');

    const activeTab = visibleTabs.find((tab) => tab.id === activeId) || visibleTabs[0] || null;

    if (!activeTab && nav.length === 0) {
        return null;
    }

    const tabItems = visibleTabs.map((tab) => ({
        key: tab.id,
        label: moduleLabel(tab, tab.label),
        icon: tab.icon || undefined,
    }));

    const body = activeTab ? (
        <div className={ui.extensionFormPageBody}>
            {sidebarActions.length > 0 ? (
                <div className={`${ui.toolbar} mb-4`}>
                    {sidebarActions.map((action) => (
                        <Button
                            key={action.label}
                            variant="secondary"
                            href={action.href}
                            native
                            icon={action.icon || undefined}
                        >
                            {moduleLabel(action, action.label)}
                        </Button>
                    ))}
                </div>
            ) : null}

            <div className={ui.panelTabsShell}>
                {tabItems.length > 0 ? (
                    <PanelTabs
                        tabs={tabItems}
                        value={activeTab.id}
                        onChange={setActiveId}
                    />
                ) : null}
                <div className={ui.panelTabsBody}>
                    {activeTab.hint ? (
                        <p className={ui.muted}>
                            {activeTab.hint_key ? moduleLabel({ label: activeTab.hint, label_key: activeTab.hint_key }, activeTab.hint) : activeTab.hint}
                        </p>
                    ) : null}
                    {(activeTab.fields || []).length > 0 ? (
                        <ExtensionSettingsFields
                            fields={activeTab.fields}
                            values={values}
                            masked={masked}
                            errors={errors}
                            layout="config"
                            onChange={onChange}
                        />
                    ) : null}
                    {(activeTab.blocks || []).length > 0 ? (
                        <ModuleSlots slots={activeTab.blocks} />
                    ) : null}
                    {(activeTab.fields || []).length > 0 ? (
                        <div className={ui.toolbar}>
                            <Button type="submit" icon="check" busy={processing}>
                                {t('admin.apps.save', 'Save settings')}
                            </Button>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    ) : null;

    return (
        <>
            <div className={ui.formPageStickyHead}>
                {header}
                <ExtensionPrimaryNav items={nav} />
            </div>
            {body}
        </>
    );
}
