import { router } from '@inertiajs/react';
import Icon from './Icon';
import Tooltip from './Tooltip';
import { ui, cx } from './classes';
import { t } from './i18n';

const DEFAULT_IGNORE = ['sort', 'dir', 'page', 'per_page'];

/**
 * True when the list query has any non-default filter value.
 * Sort / pagination keys are ignored by default.
 */
export function hasActiveFilters(query = {}, { ignore = DEFAULT_IGNORE } = {}) {
    const skip = new Set(ignore);

    return Object.entries(query || {}).some(([key, value]) => {
        if (skip.has(key)) {
            return false;
        }
        if (value === '' || value == null || value === 'all') {
            return false;
        }
        return true;
    });
}

/**
 * Compact reset control for the Action column filter row.
 * Renders only when filters are active.
 */
export default function FilterReset({
    query = {},
    onReset,
    href,
    ignore,
    label = t('admin.common.reset', 'Reset'),
    className,
}) {
    if (!hasActiveFilters(query, ignore ? { ignore } : undefined)) {
        return null;
    }

    return (
        <Tooltip content={label}>
            <button
                type="button"
                className={cx(ui.filterResetBtn, className)}
                aria-label={label}
                onClick={() => {
                    if (onReset) {
                        onReset();
                        return;
                    }
                    if (href) {
                        router.get(href);
                    }
                }}
            >
                <Icon name="refresh" className={ui.filterResetIcon} />
            </button>
        </Tooltip>
    );
}
