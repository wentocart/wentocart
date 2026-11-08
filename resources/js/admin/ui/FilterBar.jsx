import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

export default function FilterBar({ search, searchPlaceholder = t('admin.common.search', 'Search…'), onSearchSubmit, children, className, stacked }) {
    return (
        <form
            className={cx(stacked ? ui.filterBarStack : ui.filterBar, className)}
            onSubmit={(e) => {
                e.preventDefault();
                onSearchSubmit?.(e.currentTarget.q?.value ?? '');
            }}
        >
            {search !== false ? (
                <label className={cx(ui.filterSearch, stacked && 'w-full flex-none')}>
                    <span className={ui.filterSearchIcon}>
                        <Icon name="search" />
                    </span>
                    <input
                        name="q"
                        defaultValue={search || ''}
                        placeholder={searchPlaceholder}
                        className={ui.filterSearchInput}
                    />
                </label>
            ) : null}
            {stacked && children ? <div className={ui.filterFields}>{children}</div> : children}
        </form>
    );
}
