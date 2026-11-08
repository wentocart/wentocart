import { ui, cx } from './classes';
import './list-row-highlight.css';

function thClass({ actions, check, col }) {
    if (check) return ui.thCheck;
    if (actions) return ui.thActions;
    if (col === 'id') return ui.thId;
    if (col === 'status') return ui.thStatus;
    if (col === 'center') return ui.thCenter;
    return ui.th;
}

function tdClass({ actions, check, col }) {
    if (check) return ui.tdCheck;
    if (actions) return ui.tdActions;
    if (col === 'id') return ui.tdId;
    if (col === 'status') return ui.tdStatus;
    if (col === 'center') return ui.tdCenter;
    return ui.td;
}

export function Table({ children, className, ...props }) {
    return <table className={cx(ui.table, className)} {...props}>{children}</table>;
}

export function THead({ children }) {
    return <thead className={ui.thead}>{children}</thead>;
}

export function Th({ children, className, actions, check, col }) {
    // Filter-row cells pass thFilter* tokens; use those alone so header padding
    // (pt-2 / font-medium) does not fight Tailwind and inflate the row.
    const isFilterCell = className === ui.thFilter
        || className === ui.thFilterId
        || className === ui.thFilterStatus
        || className === ui.thFilterCenter;

    if (isFilterCell) {
        let filterClass = className;
        if (check) {
            filterClass = ui.thFilterCheck;
        } else if (actions) {
            filterClass = ui.thFilterActions;
        }
        return <th className={filterClass}>{children}</th>;
    }

    return (
        <th className={cx(thClass({ actions, check, col }), className)}>
            {children}
        </th>
    );
}

/**
 * @param {{
 *   children?: import('react').ReactNode,
 *   className?: string,
 *   onClick?: (event: import('react').MouseEvent<HTMLTableRowElement>) => void,
 *   selected?: boolean,
 * }} props
 * Pass `selected={boolean}` on checkbox list rows to enable the shared pen-highlight animation.
 */
export function Tr({ children, className, onClick, selected, ...props }) {
    const selectable = typeof selected === 'boolean';

    return (
        <tr
            className={cx(
                ui.tr,
                selectable && 'admin-list-row',
                selected && 'is-selected',
                onClick && ui.trClick,
                className,
            )}
            data-selected={selected ? 'true' : undefined}
            onClick={onClick}
            {...props}
        >
            {children}
        </tr>
    );
}

export function Td({ children, actions, check, col, className, ...props }) {
    return (
        <td className={cx(tdClass({ actions, check, col }), className)} {...props}>
            {actions ? <div className={ui.actionRow}>{children}</div> : children}
        </td>
    );
}
