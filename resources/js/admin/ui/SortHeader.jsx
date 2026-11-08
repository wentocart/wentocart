import Icon from './Icon';
import { Th } from './Table';
import { ui, cx } from './classes';

function SortGlyph({ active, dir }) {
    if (active) {
        return (
            <Icon
                name={dir === 'asc' ? 'chevron-up' : 'chevron-down'}
                className={cx('h-3.5 w-3.5', 'text-violet-700')}
            />
        );
    }

    return (
        <span className="inline-flex items-center text-neutral-400" aria-hidden="true">
            <Icon name="chevron-up" className="h-3 w-3" />
            <Icon name="chevron-down" className="h-3 w-3" />
        </span>
    );
}

export default function SortHeader({ column, label, sort, dir, onSort, className, col }) {
    const active = sort === column;

    return (
        <Th col={col} className={className}>
            <button type="button" className={cx(ui.sortBtn, active && ui.sortActive)} onClick={() => onSort(column)}>
                {label}
                <span className={ui.sortIcon}>
                    <SortGlyph active={active} dir={dir} />
                </span>
            </button>
        </Th>
    );
}
