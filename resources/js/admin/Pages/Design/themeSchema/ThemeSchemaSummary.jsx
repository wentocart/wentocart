import { Badge, Button, ui, t } from '../../../ui';

export default function ThemeSchemaSummary({ shop, onReset, processing }) {
    return (
        <div className={`${ui.sectionCard} space-y-4`}>
            <div>
                <p className={ui.sectionKicker}>{t('admin.theme_schema.summary_kicker', 'Active theme')}</p>
                <h3 className={`${ui.sectionTitle} mt-1`}>{shop?.theme_label || shop?.theme || 'Theme'}</h3>
                {shop?.name ? <p className={ui.sectionLead}>{shop.name}</p> : null}
            </div>

            <div className="flex flex-wrap gap-2">
                <Badge tone="primary">{t('admin.theme_schema.external', 'Theme zip')}</Badge>
                <Badge tone="neutral">{t('admin.theme_schema.shop_scope', 'This shop only')}</Badge>
            </div>

            <ul className={`${ui.muted} list-disc space-y-1.5 pl-5 text-sm`}>
                <li>{t('admin.theme_schema.tip_save', 'Save after each panel if you want to preview one section at a time.')}</li>
                <li>{t('admin.theme_schema.tip_reset', 'Reset restores the defaults shipped inside the theme zip.')}</li>
                {shop?.storefront_settings_apply === 'admin_only' ? (
                    <li>{t('admin.theme_schema.tip_admin_only', 'Storefront preview may not reflect saved values until this theme is wired to live settings.')}</li>
                ) : null}
            </ul>

            <div className="space-y-2 border-t border-neutral-100 pt-4">
                <Button type="submit" icon="check" busy={processing} className="w-full">
                    {t('admin.theme_settings.save', 'Save theme settings')}
                </Button>
                <Button type="button" variant="ghost" className="w-full" onClick={onReset}>
                    {t('admin.theme_settings.reset', 'Reset to defaults')}
                </Button>
            </div>
        </div>
    );
}
