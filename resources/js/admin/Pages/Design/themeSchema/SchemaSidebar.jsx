import { cx, ui } from '../../../ui';

export default function SchemaSidebar({ groups, activeGroupId, onSelect }) {
    return (
        <nav className={`${ui.sectionCard} !p-3`} aria-label="Theme setting panels">
            <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-neutral-400">
                Panels
            </p>
            <ul className="space-y-1">
                {groups.map((group) => {
                    const active = group.id === activeGroupId;
                    return (
                        <li key={group.id || group.label}>
                            <button
                                type="button"
                                onClick={() => onSelect(group.id)}
                                className={cx(
                                    'w-full rounded-lg px-3 py-2.5 text-left text-sm transition-colors cursor-pointer',
                                    active
                                        ? 'bg-violet-50 font-medium text-violet-800'
                                        : 'text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900',
                                )}
                            >
                                {group.label}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
