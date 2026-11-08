import { Toggle, ui, t } from '../../../ui';
import SchemaField from './SchemaField';
import { getByPath, isFullWidthField, isToggleOnlyGroup } from './utils';

function ToggleGrid({ fields, settings, onSetPath, title }) {
    return (
        <div className="space-y-3">
            {title ? (
                <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{title}</p>
            ) : null}
            <div className="grid gap-3 sm:grid-cols-2">
                {fields.map((field) => (
                    <div
                        key={field.path}
                        className="flex items-center justify-between gap-4 rounded-xl border border-neutral-200/90 bg-neutral-50/70 px-4 py-3.5"
                    >
                        <div className="min-w-0 pr-2">
                            <p className="text-sm font-medium text-neutral-900">{field.label}</p>
                            {field.hint ? <p className="mt-1 text-xs text-neutral-500">{field.hint}</p> : null}
                        </div>
                        <Toggle
                            value={Boolean(getByPath(settings, field.path))}
                            onChange={(checked) => onSetPath(field.path, checked)}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function SchemaGroupPanel({ group, settings, onSetPath }) {
    const fields = group?.fields || [];
    const toggleFields = fields.filter((field) => field.type === 'toggle');
    const otherFields = fields.filter((field) => field.type !== 'toggle');

    if (isToggleOnlyGroup(group)) {
        return (
            <div className={ui.sectionCard}>
                <div className="border-b border-neutral-100 pb-4 mb-6">
                    <p className={ui.sectionKicker}>{t('admin.theme_schema.panel', 'Panel')}</p>
                    <h3 className={`${ui.sectionTitle} mt-1`}>{group.label}</h3>
                    {group.description ? <p className={ui.sectionLead}>{group.description}</p> : null}
                </div>
                <ToggleGrid fields={fields} settings={settings} onSetPath={onSetPath} />
            </div>
        );
    }

    return (
        <div className={ui.sectionCard}>
            <div className="border-b border-neutral-100 pb-4 mb-6">
                <p className={ui.sectionKicker}>{t('admin.theme_schema.panel', 'Panel')}</p>
                <h3 className={`${ui.sectionTitle} mt-1`}>{group.label}</h3>
                {group.description ? <p className={ui.sectionLead}>{group.description}</p> : null}
            </div>

            {otherFields.length > 0 ? (
                <div className="grid gap-x-5 gap-y-6 sm:grid-cols-2">
                    {otherFields.map((field) => (
                        <div key={field.path || field.key} className={isFullWidthField(field) ? 'sm:col-span-2' : ''}>
                            <SchemaField
                                field={field}
                                value={getByPath(settings, field.path)}
                                onChange={(value) => onSetPath(field.path, value)}
                            />
                        </div>
                    ))}
                </div>
            ) : null}

            {toggleFields.length > 0 ? (
                <div className={otherFields.length > 0 ? 'mt-8 border-t border-neutral-100 pt-6' : ''}>
                    <ToggleGrid
                        fields={toggleFields}
                        settings={settings}
                        onSetPath={onSetPath}
                        title={otherFields.length > 0 ? t('admin.theme_schema.visibility', 'Visibility options') : null}
                    />
                </div>
            ) : null}
        </div>
    );
}
