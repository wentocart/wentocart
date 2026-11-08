import { Link, useSearchParams } from 'react-router-dom';
import ExtensionIcon from './ExtensionIcon.jsx';
import { t } from '../i18n.js';

function shopButtonClass(variant = 'secondary') {
    if (variant === 'primary') {
        return 'shop-btn shop-btn-primary';
    }
    if (variant === 'ghost') {
        return 'shop-btn shop-btn-ghost';
    }

    return 'shop-btn shop-btn-secondary';
}

function cellHref(href, className, children) {
    if (!href) {
        return children;
    }
    if (href.startsWith('/')) {
        return <Link className={className} to={href}>{children}</Link>;
    }

    return <a className={className} href={href}>{children}</a>;
}

function ActionControl({ action }) {
    const className = `${shopButtonClass(action.variant)} shop-module-action inline-flex items-center gap-1.5`;
    const body = (
        <>
            {action.icon ? <ExtensionIcon icon={action.icon} className="h-4 w-4" /> : null}
            <span>{action.label}</span>
        </>
    );

    return cellHref(action.href, className, body);
}

export default function ModuleList({ list }) {
    const [searchParams, setSearchParams] = useSearchParams();
    const idKey = list.id_key || 'id';
    const columns = list.columns || [];
    const rows = list.rows || [];
    const perPageOptions = list.per_page_options || [];
    const page = Number(list.page) || 1;
    const lastPage = Number(list.last_page) || 1;
    const q = searchParams.get('q') || '';
    const sort = list.sort || '';
    const dir = list.dir === 'desc' ? 'desc' : 'asc';
    const showToolbar = Boolean(list.search || perPageOptions.length || lastPage > 1);
    const hasActions = rows.some((row) => (row.actions && row.actions.length) || row.href);

    const patchParams = (next) => {
        const params = new URLSearchParams(searchParams);
        Object.entries(next).forEach(([key, value]) => {
            if (value === '' || value == null) {
                params.delete(key);
            } else {
                params.set(key, String(value));
            }
        });
        setSearchParams(params);
    };

    const toggleSort = (key) => {
        const nextDir = sort === key && dir === 'asc' ? 'desc' : 'asc';
        patchParams({ sort: key, dir: nextDir, page: '1' });
    };

    return (
        <div className="shop-module-list">
            {showToolbar ? (
                <div className="shop-module-toolbar">
                    {perPageOptions.length ? (
                        <label className="shop-module-show">
                            {t('storefront.module.show', 'Show')}
                            <select
                                className="shop-input shop-module-select"
                                value={String(list.per_page || perPageOptions[0])}
                                onChange={(e) => patchParams({ per_page: e.target.value, page: '1' })}
                            >
                                {perPageOptions.map((n) => (
                                    <option key={n} value={n}>{n}</option>
                                ))}
                            </select>
                            {t('storefront.module.entries', 'entries')}
                        </label>
                    ) : <span />}
                    {list.search ? (
                        <label className="shop-module-search">
                            {t('storefront.module.search', 'Search:')}
                            <input
                                className="shop-input"
                                value={q}
                                onChange={(e) => patchParams({ q: e.target.value, page: '1' })}
                            />
                        </label>
                    ) : null}
                </div>
            ) : null}

            <div className="shop-module-table-wrap">
                <table className="shop-module-table">
                    <thead>
                        <tr>
                            {columns.map((col) => (
                                <th key={col.key} scope="col">
                                    {col.sort ? (
                                        <button type="button" className="shop-module-sort" onClick={() => toggleSort(col.key)}>
                                            {col.label}
                                            {sort === col.key ? (dir === 'desc' ? ' ▼' : ' ▲') : ''}
                                        </button>
                                    ) : col.label}
                                </th>
                            ))}
                            {hasActions ? <th scope="col">{t('storefront.module.action', 'Action')}</th> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length + (hasActions ? 1 : 0)} className="shop-module-empty">
                                    {list.empty || t('storefront.module.empty', 'No records yet.')}
                                </td>
                            </tr>
                        ) : rows.map((row) => {
                            const actions = row.actions?.length
                                ? row.actions
                                : (row.href ? [{ label: 'View', href: row.href, icon: { kind: 'kit', value: 'eye' }, variant: 'ghost' }] : []);

                            return (
                                <tr key={row[idKey]}>
                                    {columns.map((col) => {
                                        const value = row[col.key] ?? '';
                                        const tone = row[`${col.key}_tone`];
                                        if (col.badge && value) {
                                            return (
                                                <td key={col.key}>
                                                    <span className={`shop-module-badge is-${tone || 'neutral'}`}>{value}</span>
                                                </td>
                                            );
                                        }

                                        return <td key={col.key}>{value}</td>;
                                    })}
                                    {hasActions ? (
                                        <td className="shop-module-actions">
                                            {actions.map((action) => (
                                                <ActionControl key={`${row[idKey]}-${action.label}`} action={action} />
                                            ))}
                                        </td>
                                    ) : null}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {lastPage > 1 || list.total != null ? (
                <div className="shop-module-pager">
                    <p>
                        {list.total != null
                            ? t('storefront.module.showing_of', 'Showing {count} of {total}')
                                .replace('{count}', String(rows.length))
                                .replace('{total}', String(list.total))
                            : t('storefront.module.showing', 'Showing {count}').replace('{count}', String(rows.length))}
                    </p>
                    {lastPage > 1 ? (
                        <div className="shop-module-pages">
                            <button type="button" className="shop-btn shop-btn-ghost" disabled={page <= 1} onClick={() => patchParams({ page: String(page - 1) })}>
                                {t('storefront.module.previous', 'Previous')}
                            </button>
                            <span>{page}</span>
                            <button type="button" className="shop-btn shop-btn-ghost" disabled={page >= lastPage} onClick={() => patchParams({ page: String(page + 1) })}>
                                {t('storefront.module.next', 'Next')}
                            </button>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
