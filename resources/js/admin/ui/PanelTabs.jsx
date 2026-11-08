import Icon from './Icon';
import { ui, cx } from './classes';

/**
 * Card-attached settings sub-tabs. The active tab blends into the panel body.
 */
export default function PanelTabs({
    tabs = [],
    value,
    onChange,
    className,
    barClassName,
}) {
    const items = tabs.filter((tab) => tab?.key || tab?.id);
    if (items.length === 0) {
        return null;
    }

    const active = value || items[0].key || items[0].id;

    return (
        <div className={cx(ui.panelTabsBar, barClassName, className)} role="tablist">
            {items.map((tab) => {
                const key = tab.key || tab.id;
                const isActive = active === key;

                return (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={isActive}
                        className={isActive ? ui.panelTabActive : ui.panelTab}
                        onClick={() => onChange?.(key)}
                    >
                        {tab.icon ? <Icon name={tab.icon} className={ui.panelTabIcon} /> : null}
                        <span>{tab.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
