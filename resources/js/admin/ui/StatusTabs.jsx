import { ui, cx } from './classes';

export default function StatusTabs({ tabs, value, onChange, className, sticky = false, stickyClassName }) {
    const content = (
        <div className={cx(ui.statusTabs, className)}>
            {tabs.map((tab) => {
                const active = value === tab.key;
                const error = !!tab.error;
                const className = error
                    ? (active ? ui.statusTabErrorActive : ui.statusTabError)
                    : (active ? ui.statusTabActive : ui.statusTab);
                return (
                    <button
                        key={tab.key}
                        type="button"
                        className={className}
                        aria-invalid={error || undefined}
                        onClick={() => onChange?.(tab.key)}
                    >
                        {tab.label}
                        {tab.count != null ? (
                            <span className={error ? ui.statusTabErrorCount : ui.statusTabCount}>
                                {Number(tab.count).toLocaleString()}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );

    if (!sticky) {
        return content;
    }

    return (
        <div className={cx(ui.statusTabsStickyWrap, stickyClassName)}>
            {content}
        </div>
    );
}
