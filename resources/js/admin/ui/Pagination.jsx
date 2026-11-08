import { router } from '@inertiajs/react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

const DEFAULT_PER_PAGE_OPTIONS = [10, 20, 25, 50, 100];

/**
 * Build page tokens for the shared Back Office pager.
 *
 * Target format:
 *   < 1 2 3 ... 7 8 9 >
 * Always keep the first 3 and last 3 pages when there are enough pages.
 * If the current page falls in the skipped range, insert it (alone) so it stays highlighted:
 *   < 1 2 3 ... 5 ... 7 8 9 >
 *
 * @returns {Array<number|'ellipsis'>}
 */
export function buildPageItems(current, last, boundaryCount = 3) {
    const total = Math.max(1, Number(last) || 1);
    const page = Math.min(Math.max(1, Number(current) || 1), total);
    const boundary = Math.max(1, Number(boundaryCount) || 3);

    // 1..7 fits first+last boundaries without a real skip — show every page.
    if (total <= boundary * 2 + 1) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const set = new Set();
    for (let i = 1; i <= boundary; i += 1) {
        set.add(i);
    }
    for (let i = total - boundary + 1; i <= total; i += 1) {
        set.add(i);
    }
    // Keep the active page visible when it sits between the two edges.
    set.add(page);

    const sorted = [...set].sort((a, b) => a - b);
    const items = [];
    sorted.forEach((n, index) => {
        if (index > 0) {
            const gap = n - sorted[index - 1];
            if (gap === 2) {
                items.push(sorted[index - 1] + 1);
            } else if (gap > 2) {
                items.push('ellipsis');
            }
        }
        items.push(n);
    });
    return items;
}

function cleanQuery(query = {}) {
    const payload = { ...query };
    Object.keys(payload).forEach((key) => {
        if (payload[key] === '' || payload[key] == null || payload[key] === 'all') {
            delete payload[key];
        }
    });
    return payload;
}

/**
 * Shared Back Office pagination:
 * <  1  2  3  …  7  8  9  >     [ 10 / page ▼ ]
 */
export default function Pagination({
    paginator,
    query = {},
    className,
    inline = false,
    showPerPage = true,
    perPageOptions = DEFAULT_PER_PAGE_OPTIONS,
    onPageChange = null,
    onPerPageChange = null,
    showSummary = !inline,
}) {
    if (!paginator) {
        return null;
    }

    const current = Number(paginator.current_page) || 1;
    const simple = paginator.last_page == null;
    const last = simple
        ? (paginator.next_page_url ? current + 1 : current)
        : Math.max(1, Number(paginator.last_page) || 1);
    const hasPrev = current > 1;
    const hasNext = simple ? Boolean(paginator.next_page_url) : current < last;
    const perPage = Number(paginator.per_page) || Number(query.per_page) || perPageOptions[0] || 10;
    const pageItems = simple ? [current] : buildPageItems(current, last);

    const sizeOptions = [...new Set([...perPageOptions, perPage])]
        .filter((n) => Number.isFinite(n) && n > 0)
        .sort((a, b) => a - b);

    const navigate = (page, nextPerPage = perPage) => {
        if (onPageChange && nextPerPage === perPage) {
            onPageChange(page);
            return;
        }
        if (onPerPageChange && nextPerPage !== perPage) {
            onPerPageChange(nextPerPage);
            return;
        }
        const payload = cleanQuery({
            ...query,
            page,
            per_page: nextPerPage,
        });

        if (typeof document !== 'undefined' && document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }

        const scrollListToTop = () => {
            if (typeof document === 'undefined') {
                return;
            }
            document.querySelectorAll('[scroll-region]').forEach((el) => {
                if (typeof el.scrollTo === 'function') {
                    el.scrollTo(0, 0);
                } else {
                    el.scrollTop = 0;
                    el.scrollLeft = 0;
                }
            });
            window.scrollTo(0, 0);
        };

        router.get(window.location.pathname, payload, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                scrollListToTop();
                window.requestAnimationFrame(scrollListToTop);
            },
            onFinish: () => {
                scrollListToTop();
                window.requestAnimationFrame(scrollListToTop);
                window.setTimeout(scrollListToTop, 0);
                window.setTimeout(scrollListToTop, 100);
            },
        });
    };

    const goPage = (page) => {
        if (page < 1 || (!simple && page > last) || page === current) {
            return;
        }
        navigate(page, perPage);
    };

    const changePerPage = (value) => {
        const next = Number(value) || perPage;
        if (next === perPage) {
            return;
        }
        if (onPerPageChange) {
            onPerPageChange(next);
            return;
        }
        navigate(1, next);
    };

    const nav = (
        <nav className={ui.pager} aria-label={t('admin.common.pagination', 'Pagination')}>
            <div className={ui.pagerCluster}>
                <button
                    type="button"
                    className={ui.pageBtnNav}
                    disabled={!hasPrev}
                    onClick={() => goPage(current - 1)}
                    aria-label={t('admin.common.previous_page', 'Previous page')}
                >
                    <Icon name="chevron-left" className="!h-4 !w-4 stroke-[2.25]" />
                </button>
                {pageItems.map((item, index) => {
                    if (item === 'ellipsis') {
                        return (
                            <span key={`ellipsis-${index}`} className={ui.pageEllipsis} aria-hidden>
                                …
                            </span>
                        );
                    }
                    const isActive = item === current;
                    return (
                        <button
                            key={item}
                            type="button"
                            className={isActive ? ui.pageBtnActive : ui.pageBtn}
                            onClick={() => goPage(item)}
                            aria-label={t('admin.common.page_n', 'Page :page').replace(':page', String(item))}
                            aria-current={isActive ? 'page' : undefined}
                            disabled={isActive}
                        >
                            {item}
                        </button>
                    );
                })}
                <button
                    type="button"
                    className={ui.pageBtnNav}
                    disabled={!hasNext}
                    onClick={() => goPage(current + 1)}
                    aria-label={t('admin.common.next_page', 'Next page')}
                >
                    <Icon name="chevron-right" className="!h-4 !w-4 stroke-[2.25]" />
                </button>
            </div>
            {showPerPage ? (
                <div className={ui.pageSizeWrap}>
                    <select
                        className={ui.pageSizeSelect}
                        value={String(perPage)}
                        aria-label={t('admin.common.per_page', 'Items per page')}
                        onChange={(e) => changePerPage(e.target.value)}
                    >
                        {sizeOptions.map((n) => (
                            <option key={n} value={n}>
                                {t('admin.common.n_per_page', ':n / page').replace(':n', String(n))}
                            </option>
                        ))}
                    </select>
                    <Icon name="chevron-down" className={ui.pageSizeChevron} />
                </div>
            ) : null}
        </nav>
    );

    const summary = showSummary ? (
        <span>
            {t('admin.common.showing', 'Showing')} {paginator.from || 0} {t('admin.common.to', 'to')} {paginator.to || 0}{' '}
            {t('admin.common.of', 'of')} {Number(paginator.total || 0).toLocaleString()}
        </span>
    ) : null;

    if (inline) {
        return (
            <div className={cx('flex min-w-0 shrink-0 flex-wrap items-center justify-end', className)}>
                {nav}
            </div>
        );
    }

    return (
        <div className={cx(ui.listFooter, className)}>
            {summary}
            {nav}
        </div>
    );
}
