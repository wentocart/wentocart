import { cx, ui } from '../../ui';
import { moduleLabel } from './moduleLabel';

/**
 * Top-level extension screens (Configuration / Transactions / Payment links).
 * Items are sanitized page-document links with id, label, href and active.
 */
export default function ExtensionPrimaryNav({ items = [], className }) {
    const tabs = items.filter((item) => item?.id && item?.label && item?.href);
    if (tabs.length === 0) {
        return null;
    }

    return (
        <nav className={cx(ui.statusTabs, className || ui.formEditTabs, ui.extensionPrimaryNav)} aria-label="Extension pages">
            {tabs.map((item) => (
                <a
                    key={item.id}
                    href={item.href}
                    aria-current={item.active ? 'page' : undefined}
                    className={item.active ? ui.extensionNavTabActive : ui.extensionNavTab}
                >
                    {moduleLabel(item, item.label)}
                </a>
            ))}
        </nav>
    );
}
