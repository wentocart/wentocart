import { Link } from '@inertiajs/react';
import Icon from './Icon';
import { ui, cx } from './classes';

const tones = {
    primary: ui.statIconPrimary,
    success: ui.statIconSuccess,
    danger: ui.statIconDanger,
    info: ui.statIconInfo,
    warning: ui.statIconWarning,
    neutral: ui.statIconNeutral,
};

const tonesStack = {
    primary: 'bg-[#F3EEFF] text-[#6D28D9]',
    success: 'bg-[#EAFBF3] text-[#10B981]',
    danger: 'bg-[#FFF0F2] text-[#F43F5E]',
    info: 'bg-[#EFF6FF] text-[#3B82F6]',
    warning: 'bg-[#FFF7E6] text-[#F59E0B]',
    neutral: ui.statIconNeutral,
};

/**
 * Summary cards above list pages (All / Active / Inactive, etc.).
 * items: [{ key, label, value, hint?, icon?, tone?, href?, active? }]
 * size: md | lg | stack (stack = Figma KPI: icon + label / number / hint)
 */
export default function StatCards({ items = [], className, size = 'md' }) {
    if (!items.length) {
        return null;
    }

    const stack = size === 'stack';
    const large = size === 'lg';
    const count = items.length;
    const xlCols = {
        1: 'xl:grid-cols-1',
        2: 'xl:grid-cols-2',
        3: 'xl:grid-cols-3',
        4: 'xl:grid-cols-4',
        5: 'xl:grid-cols-5',
    }[Math.min(count, 5)] || 'xl:grid-cols-4';

    return (
        <div className={cx('grid gap-3.5 sm:grid-cols-2 mb-4', xlCols, className)}>
            {items.map((item) => {
                const clickable = Boolean(item.href);
                const className = cx(
                    stack ? ui.statCardStack : (large ? ui.statCardLg : ui.statCard),
                    clickable && ui.statCardClick,
                    item.active && ui.statCardActive,
                );
                const iconTone = (stack ? tonesStack : tones)[item.tone] || tones.neutral;
                const body = stack ? (
                    <>
                        {item.icon ? (
                            <span className={cx(ui.statIconStack, iconTone)}>
                                <Icon name={item.icon} className={ui.iconLg} />
                            </span>
                        ) : null}
                        <div className="min-w-0">
                            <div className={ui.statLabelStack}>{item.label}</div>
                            <div className={ui.statValueStack}>{item.value}</div>
                            {item.hint ? <div className={ui.statHintStack}>{item.hint}</div> : null}
                        </div>
                    </>
                ) : (
                    <>
                        {item.icon ? (
                            <span className={cx(large ? ui.statIconLg : ui.statIcon, iconTone)}>
                                <Icon name={item.icon} className={large ? ui.iconLg : undefined} />
                            </span>
                        ) : null}
                        <div className={ui.statBody}>
                            <div className={large ? ui.statLabelLg : ui.statLabel}>{item.label}</div>
                            {item.hint ? <div className={large ? ui.statHintLg : ui.statHint}>{item.hint}</div> : null}
                        </div>
                        <div className={large ? ui.statValueLg : ui.statValue}>{item.value}</div>
                    </>
                );

                if (clickable) {
                    return (
                        <Link
                            key={item.key || item.label}
                            href={item.href}
                            className={className}
                            aria-current={item.active ? 'page' : undefined}
                            preserveState
                            preserveScroll
                        >
                            {body}
                        </Link>
                    );
                }

                return (
                    <div key={item.key || item.label} className={className}>
                        {body}
                    </div>
                );
            })}
        </div>
    );
}
