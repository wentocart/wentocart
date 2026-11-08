/**
 * Pure helpers for the storefront search results summary.
 * Kept free of React so Node can unit-test heading/count behaviour.
 */

/** Shop search redirects are internal paths only. */
export function searchRedirectPath(value) {
    const raw = String(value || '').trim();
    if (!raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\')) {
        return '';
    }
    const path = raw.split(/[?#]/)[0];
    if (path === '/search' || path === '') {
        return '';
    }

    return raw;
}

export function hasSearchQuery(query) {
    return String(query || '').trim() !== '';
}

function applyCount(template, count) {
    return String(template || '').replaceAll('{count}', String(count));
}

/**
 * Build the muted line under the search heading.
 * @param {{
 *   total?: number|null,
 *   oneLabel: string,
 *   manyLabel: string,
 *   leadLabel: string,
 * }} opts
 */
export function searchSummaryCountLabel({ total, oneLabel, manyLabel, leadLabel }) {
    if (total == null || Number.isNaN(Number(total))) {
        return leadLabel;
    }

    const count = Number(total);
    if (count === 1) {
        return applyCount(oneLabel, count);
    }

    return applyCount(manyLabel, count);
}

/**
 * Compact summary model for the search results header.
 * @param {{ query?: string, total?: number|null, labels: {
 *   results: string, title: string, one: string, many: string, lead: string
 * }}} opts
 */
export function buildSearchSummary({ query = '', total = null, labels }) {
    const q = String(query || '').trim();
    const hasQuery = q !== '';

    return {
        hasQuery,
        query: q,
        title: hasQuery ? labels.results : labels.title,
        countLabel: searchSummaryCountLabel({
            total: hasQuery || total != null ? total : null,
            oneLabel: labels.one,
            manyLabel: labels.many,
            leadLabel: labels.lead,
        }),
    };
}
