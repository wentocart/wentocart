import { ui, cx } from './classes';
import { Table } from './Table';
import Pagination from './Pagination';
import { t } from './i18n';

export function ListPanel({ tabs, filters, bulk, children, footer, className, table = true }) {
    return (
        <div className={cx(ui.listPanel, className)}>
            {tabs}
            {filters}
            {bulk}
            <div className={table ? ui.tableWrap : undefined}>{children}</div>
            {footer}
        </div>
    );
}

export function EmptyState({ children = t('admin.common.no_records', 'No records yet.'), colSpan = 8 }) {
    return (
        <tr>
            <td colSpan={colSpan}>
                <div className={ui.empty}>{children}</div>
            </td>
        </tr>
    );
}

export function DataTable({ children, className }) {
    return <Table className={className}>{children}</Table>;
}

export function ListFooter({ left, pagination, query }) {
    return (
        <div className={ui.listFooter}>
            <div className={cx(ui.row, 'min-w-0 flex-1')}>
                {left ?? (
                    pagination ? (
                        <span>
                            {t('admin.common.showing', 'Showing')} {pagination.from || 0} {t('admin.common.to', 'to')} {pagination.to || 0}
                            {pagination.total != null ? (
                                <> {t('admin.common.of', 'of')}{' '}{Number(pagination.total || 0).toLocaleString()} {t('admin.common.results', 'results')}</>
                            ) : (
                                <> {t('admin.common.on_this_page', 'on this page')}</>
                            )}
                        </span>
                    ) : null
                )}
            </div>
            <Pagination paginator={pagination} query={query} inline />
        </div>
    );
}
